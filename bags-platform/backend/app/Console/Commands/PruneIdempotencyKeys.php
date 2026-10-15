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
        $deleted = InventoryIdempotencyKey::query()
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        $this->info("تم حذف {$deleted} مفتاح عدم تكرار قديم.");

        return self::SUCCESS;
    }
}
