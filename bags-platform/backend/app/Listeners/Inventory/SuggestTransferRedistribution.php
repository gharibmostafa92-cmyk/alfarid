<?php

declare(strict_types=1);

namespace App\Listeners\Inventory;

use App\Events\Inventory\StockLowDetected;
use App\Models\ProductVariant;
use App\Notifications\StaffRecipients;
use App\Notifications\Inventory\TransferSuggestionNotification;
use App\Services\Ai\Inventory\InventoryCoverage;
use App\Services\Shared\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Proactive stock redistribution suggestions computed deterministically in PHP (0 LLM tokens).
 * Evaluated when ai_inventory_proactive_enabled setting is active.
 */
class SuggestTransferRedistribution implements ShouldQueue
{
    public function handle(StockLowDetected $event): void
    {
        if (! app(SettingService::class)->getBool('ai_inventory_proactive_enabled', false)) {
            return;
        }

        $targetMetrics = InventoryCoverage::metricsByBranch($event->productVariantId, [$event->branchId])[$event->branchId];
        $lowCoverage = $targetMetrics['coverage_days'];
        // Only trigger when target branch coverage is critically low (<= 7 days or zero stock).
        if ($lowCoverage !== null && $lowCoverage > 7) {
            return;
        }
        $daily = $targetMetrics['avg_daily_sale'];
        if ($daily <= 0) {
            return;
        }

        // Identify donor branch holding surplus stock (> 60 days coverage).
        $donors = DB::table('stock_levels')
            ->selectRaw('branch_id, SUM(on_hand - reserved - damaged) AS available')
            ->where('product_variant_id', $event->productVariantId)
            ->where('branch_id', '!=', $event->branchId)
            ->groupBy('branch_id')
            ->havingRaw('SUM(on_hand - reserved - damaged) > 0')
            ->orderByDesc('available')
            ->limit(10)->get()
            ->map(fn ($l) => ['branch_id' => (int) $l->branch_id, 'available' => (float) $l->available]);
        $donorMetrics = InventoryCoverage::metricsByBranch(
            $event->productVariantId,
            $donors->pluck('branch_id')->all(),
        );
        $donor = $donors->first(function ($candidate) use ($donorMetrics): bool {
            $coverage = $donorMetrics[$candidate['branch_id']]['coverage_days'] ?? null;

            return $coverage === null || $coverage > 60;
        });
        if ($donor === null) {
            return;
        }

        $need = max(1.0, round($daily * 30 - $targetMetrics['available'], 0));
        $donorDaily = $donorMetrics[$donor['branch_id']]['avg_daily_sale'];
        $surplus = $donorDaily > 0
            ? max(0.0, $donor['available'] - $donorDaily * 60)
            : $donor['available'];
        $qty = (int) min($need, $surplus);
        if ($qty < 1) {
            return;
        }

        $variant = ProductVariant::query()->with('product:id,name_ar')->find($event->productVariantId);
        $sku = $variant?->sku;
        $label = $variant?->product?->name_ar ?? $sku ?? ('#'.$event->productVariantId);
        $prompt = "جهز تحويل {$qty} قطعة من {$label} (SKU {$sku}) من الفرع #{$donor['branch_id']} للفرع #{$event->branchId}";

        $recipients = StaffRecipients::withAnyPermission(['transfers.create'], [$event->branchId, $donor['branch_id']])
            ->merge(StaffRecipients::branchManagers($event->branchId))
            ->unique('id');
        if ($recipients->isEmpty()) {
            return;
        }

        DB::transaction(fn () => Notification::sendNow($recipients, new TransferSuggestionNotification(
            $event->productVariantId, $sku, $donor['branch_id'], $event->branchId,
            $qty, $lowCoverage, $donorMetrics[$donor['branch_id']]['coverage_days'], $prompt,
        ), ['database']));
    }
}
