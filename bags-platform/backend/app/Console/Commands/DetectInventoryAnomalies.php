<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StockMovementType;
use App\Models\Stocktake;
use App\Notifications\InventoryAnomalyDigestNotification;
use App\Notifications\StaffRecipients;
use App\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * 5.3: كشف الأنماط الغريبة أسبوعيًا — ملاحظات للمراجعة تظهر للي معاه
 * ai_agent.logs.view بس، مش اتهامات.
 */
class DetectInventoryAnomalies extends Command
{
    protected $signature = 'inventory:detect-anomalies';

    protected $description = 'Flag unusual inventory patterns for review (adjustment rates, repeat shortages).';

    public function handle(SettingService $settings): int
    {
        // entry point للتشخيص: المجدول يعمل بلا tty، والتشغيل اليدوي بـ tty.
        $source = function_exists('posix_isatty') && @posix_isatty(STDOUT) ? 'cli-manual' : 'scheduler';
        logger()->info('inventory.anomalies.run', ['entry_point' => $source]);
        if (! $settings->getBool('ai_inventory_proactive_enabled', false)) {
            $this->info('Proactive inventory engine is disabled.');

            return self::SUCCESS;
        }

        $notes = [];
        $since = now()->subDays(30);

        // مستخدم نسبة تسويات الخصم بتاعته أعلى من غيره بكتير.
        $rates = DB::table('stock_movements')
            ->selectRaw('created_by, COUNT(*) AS c')
            ->where('created_at', '>=', $since)
            ->whereIn('type', [StockMovementType::AdjustmentOut->value, StockMovementType::DamageWriteOff->value])
            ->whereNotNull('created_by')
            ->groupBy('created_by')
            ->havingRaw('COUNT(*) >= 5')
            ->orderByDesc('c')->get();
        if ($rates->count() >= 3) {
            $sorted = $rates->pluck('c')->sort()->values();
            $median = (float) $sorted[(int) floor($sorted->count() / 2)];
            foreach ($rates as $row) {
                if ($median > 0 && (float) $row->c > $median * 3) {
                    $notes[] = [
                        'title' => 'معدل تسويات خصم مرتفع',
                        'detail' => "المستخدم #{$row->created_by} سجّل {$row->c} تسوية خصم آخر 30 يوم (الوسيط {$median}). راجع الأسباب قبل أي استنتاج.",
                    ];
                }
            }
        }

        // موقع العجز بيتكرر فيه (جرود معتمدة آخر 90 يوم).
        $locs = DB::table('stocktake_items as si')
            ->join('stocktakes as s', 's.id', '=', 'si.stocktake_id')
            ->selectRaw('s.location_id, COUNT(*) AS c')
            ->where('s.status', 'approved')
            ->where('s.created_at', '>=', now()->subDays(90))
            ->whereNotNull('s.location_id')
            ->whereRaw('(si.counted_qty - si.system_qty) < 0')
            ->groupBy('s.location_id')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('c')->limit(5)->get();
        foreach ($locs as $row) {
            $notes[] = [
                'title' => 'عجز متكرر في موقع',
                'detail' => "الموقع #{$row->location_id} ظهر فيه عجز {$row->c} مرات آخر 90 يوم. احتمال خطأ ترتيب أو تسجيل.",
            ];
        }

        // استلامات تحويل بعجز متكرر من نفس المصدر.
        $srcs = DB::table('stock_movements')
            ->selectRaw('branch_id, COUNT(*) AS c')
            ->where('type', StockMovementType::TransferShortage->value)
            ->where('created_at', '>=', now()->subDays(90))
            ->groupBy('branch_id')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('c')->limit(5)->get();
        foreach ($srcs as $row) {
            $notes[] = [
                'title' => 'عجز استلام متكرر',
                'detail' => "الفرع #{$row->branch_id} سجّل عجز استلام {$row->c} مرات آخر 90 يوم. راجع التغليف والعد عند الشحن.",
            ];
        }

        if ($notes === []) {
            $this->info('No unusual patterns detected.');

            return self::SUCCESS;
        }

        $recipients = StaffRecipients::withAnyPermission(['ai_agent.logs.view']);
        if ($recipients->isEmpty()) {
            $this->warn('Anomalies found but nobody holds ai_agent.logs.view.');

            return self::SUCCESS;
        }

        Notification::sendNow($recipients, new InventoryAnomalyDigestNotification($notes), ['database']);
        $this->info('Flagged '.count($notes).' notes for review.');

        return self::SUCCESS;
    }
}
