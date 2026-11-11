<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes historical staff audit trail entries beyond the configured retention window in chunks.
 */
final class PruneStaffAuditLogs extends Command
{
    protected $signature = 'staff:prune-audit-logs';

    protected $description = 'Prune staff audit entries older than the configured retention period';

    /**
     * Execute the console command.
     *
     * @return int Command exit code (0 for success).
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(max(365, (int) config('auth.staff_audit_retention_days', 365)));
        do {
            // L6: loop on progress, not on an exact full batch — the 1000-row
            // batch size lives inside the SQL function and must not leak here.
            $removed = (int) DB::scalar('SELECT alfarid_prune_staff_audit_logs(?)', [$cutoff], false);
        } while ($removed > 0);

        return self::SUCCESS;
    }
}
