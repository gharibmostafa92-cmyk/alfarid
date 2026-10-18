<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;

/**
 * تنظيف سلال الزوار القديمة (F-29): سلات الزوار (customer_id = null)
 * لا تُستهلك بإتمام الشراء تتراكم بلا حد، وتُبقي بنودها متغيرات المنتجات
 * مرهونة. الحذف على دفعات؛ بنود السلة تُحذف بالتسلسل (cart_id cascade).
 */
class PruneGuestCarts extends Command
{
    protected $signature = 'carts:prune-guests {--days=7 : الاحتفاظ بسلال الزوار بعدد الأيام هذا}';

    protected $description = 'Delete abandoned guest carts older than the retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = Cart::query()
                ->whereNull('customer_id')
                ->where('updated_at', '<', $cutoff)
                ->limit(1000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += Cart::query()->whereIn('id', $ids)->delete();
        } while (true);

        $this->info("Pruned {$deleted} guest carts older than {$days} days.");

        return self::SUCCESS;
    }
}
