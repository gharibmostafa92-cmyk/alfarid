<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InventoryIdempotencyKey;
use Illuminate\Console\Command;

/**
 * تنظيف مفاتيح عدم التكرار القديمة (L-2): المفتاح وظيفته تغطية نافذة إعادة
 * المحاولة، فالاحتفاظ به أكثر من 7 أيام تراكم بلا فائدة. يُجدول يوميًا.
 */
class PruneIdempotencyKeys extends Command
{
    protected $signature = 'inventory:prune-idempotency-keys';

    protected $description = 'حذف مفاتيح عدم تكرار المخزون الأقدم من 7 أيام';

    public function handle(): int
    {
        $deleted = 0;
        $cutoff = now()->subDays(7);
        do {
            $ids = InventoryIdempotencyKey::query()->where('created_at', '<', $cutoff)
                ->orderBy('id')->limit(1000)->pluck('id');
            $deleted += InventoryIdempotencyKey::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("تم حذف {$deleted} مفتاح عدم تكرار قديم.");

        return self::SUCCESS;
    }
}
