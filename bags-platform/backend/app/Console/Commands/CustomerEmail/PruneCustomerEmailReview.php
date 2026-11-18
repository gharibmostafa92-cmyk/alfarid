<?php

declare(strict_types=1);

namespace App\Console\Commands\CustomerEmail;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PruneCustomerEmailReview extends Command
{
    protected $signature = 'customer-email:prune-review';

    protected $description = 'Clear retained message content from old customer email review records';

    private const BATCH_SIZE = 100;

    private const MAX_PER_RUN = 1000;

    public function handle(): int
    {
        $cutoff = now()->subDays(7);
        $cleaned = 0;

        while ($cleaned < self::MAX_PER_RUN) {
            $ids = DB::table('customer_email_outbox')
                ->where('status', 'review')
                ->whereNotNull('payload')
                ->where('updated_at', '<=', $cutoff)
                ->orderBy('updated_at')
                ->orderBy('id')
                ->limit(min(self::BATCH_SIZE, self::MAX_PER_RUN - $cleaned))
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $cleaned += DB::table('customer_email_outbox')
                ->whereIn('id', $ids)
                ->where('status', 'review')
                ->whereNotNull('payload')
                ->where('updated_at', '<=', $cutoff)
                ->update(['payload' => null]);
        }

        Log::info('Customer email review payload cleanup completed', ['records_cleared' => $cleaned]);

        return self::SUCCESS;
    }
}
