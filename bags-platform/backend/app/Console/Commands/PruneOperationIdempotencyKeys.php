<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** حذف مفاتيح عدم تكرار العمليات الأقدم من نافذة إعادة المحاولة. */
class PruneOperationIdempotencyKeys extends Command
{
    protected $signature = 'operations:prune-idempotency-keys {--days=90 : الاحتفاظ بعدد الأيام هذا}';

    protected $description = 'حذف مفاتيح عدم تكرار العمليات الأقدم من نافذة الاحتفاظ';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deleted = 0;
        do {
            $ids = DB::table('operation_idempotency_keys')->where('created_at', '<', $cutoff)
                ->orderBy('id')->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += DB::table('operation_idempotency_keys')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("تم حذف {$deleted} مفتاح عدم تكرار عمليات قديم.");

        return self::SUCCESS;
    }
}
