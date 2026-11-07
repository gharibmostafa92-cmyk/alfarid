<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StockMovementType;
use App\Models\Stocktake;
use App\Notifications\InventoryAnomalyDigestNotification;
use App\Notifications\StaffRecipients;
use App\Services\BranchAccess;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Detects statistical inventory anomalies and delivers branch-scoped review digests.
 */
class DetectInventoryAnomalies extends Command
{
    protected $signature = 'inventory:detect-anomalies';

    protected $description = 'Flag unusual inventory patterns for review (adjustment rates, repeat shortages).';

    /**
     * Execute the anomaly detection command.
     *
     * @param  SettingService  $settings  System settings service.
     * @return int Command exit code (0 for success).
     */
    public function handle(SettingService $settings): int
    {
        // Record entry point to distinguish automated cron runs from manual interactive executions.
        $source = function_exists('posix_isatty') && @posix_isatty(STDOUT) ? 'cli-manual' : 'scheduler';
        logger()->info('inventory.anomalies.run', ['entry_point' => $source]);
        if (! $settings->getBool('ai_inventory_proactive_enabled', false)) {
            $this->info('Proactive inventory engine is disabled.');

            return self::SUCCESS;
        }

        $notes = [];
        $since = now()->subDays(30);

        // Flag users whose inventory write-off frequency significantly exceeds team median.
        $rates = DB::table('stock_movements')
            ->selectRaw('created_by, COUNT(*) AS c, COALESCE(json_agg(DISTINCT branch_id), \'[]\') AS branches')
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
                        'branch_ids' => array_values(array_filter(array_map(
                            'intval',
                            json_decode((string) $row->branches, true) ?? []
                        ))),
                    ];
                }
            }
        }

        // Identify storage locations experiencing recurrent stocktake deficits over the last 90 days.
        $locs = DB::table('stocktake_items as si')
            ->join('stocktakes as s', 's.id', '=', 'si.stocktake_id')
            ->join('locations as loc', 'loc.id', '=', 's.location_id')
            ->selectRaw('s.location_id, loc.branch_id, COUNT(*) AS c')
            ->where('s.status', 'approved')
            ->where('s.created_at', '>=', now()->subDays(90))
            ->whereNotNull('s.location_id')
            ->whereRaw('(si.counted_qty - si.system_qty) < 0')
            ->groupBy('s.location_id', 'loc.branch_id')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('c')->limit(5)->get();
        foreach ($locs as $row) {
            $notes[] = [
                'title' => 'عجز متكرر في موقع',
                'detail' => "الموقع #{$row->location_id} ظهر فيه عجز {$row->c} مرات آخر 90 يوم. احتمال خطأ ترتيب أو تسجيل.",
                'branch_ids' => [(int) $row->branch_id],
            ];
        }

        // Detect branches with recurring transfer shortages during receiving inspection.
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
                'branch_ids' => [(int) $row->branch_id],
            ];
        }

        if ($notes === []) {
            $this->info('No unusual patterns detected.');

            return self::SUCCESS;
        }

        // Route anomaly notes strictly according to branch scoping: central staff receive all notes, branch staff see only their own.
        $recipients = StaffRecipients::withAnyPermission(['ai_agent.logs.view']);
        if ($recipients->isEmpty()) {
            $this->warn('Anomalies found but nobody holds ai_agent.logs.view.');

            return self::SUCCESS;
        }

        $central = collect();
        $perBranch = [];
        foreach ($recipients as $recipient) {
            try {
                $scope = app(BranchAccess::class)->restrictedBranch($recipient);
            } catch (AuthorizationException) {
                // Stopped branches stay scoped to their branch ID; unassigned users retain global view.
                $scope = $recipient->branch_id !== null ? (int) $recipient->branch_id : null;
            }
            if ($scope === null) {
                $central->push($recipient);
            } else {
                $perBranch[$scope] ??= collect();
                $perBranch[$scope]->push($recipient);
            }
        }

        $visible = static fn (array $note): array => ['title' => $note['title'], 'detail' => $note['detail']];
        $sent = 0;
        if ($central->isNotEmpty()) {
            Notification::sendNow($central, new InventoryAnomalyDigestNotification(array_map($visible, $notes)), ['database']);
            $sent += $central->count();
        }
        foreach ($perBranch as $branchId => $holders) {
            $scoped = array_values(array_filter(
                $notes,
                static fn (array $note): bool => in_array($branchId, $note['branch_ids'] ?? [], true)
            ));
            if ($scoped === []) {
                continue;
            }
            Notification::sendNow($holders, new InventoryAnomalyDigestNotification(array_map($visible, $scoped)), ['database']);
            $sent += $holders->count();
        }
        $this->info('Flagged '.count($notes).' notes for review ('.$sent.' deliveries).');

        return self::SUCCESS;
    }
}
