<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneStaffAuditLogs extends Command
{
    protected $signature = 'staff:prune-audit-logs';

    protected $description = 'Prune staff audit entries older than the configured retention period';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(30, (int) config('auth.staff_audit_retention_days', 365)));
        do {
            $ids = DB::table('staff_audit_logs')->where('created_at', '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id');
            DB::table('staff_audit_logs')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        return self::SUCCESS;
    }
}
