<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\SalesChannel;
use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Observers\CatalogRetailPriceObserver;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * task.txt §2 task 25 (+ §3 background tasks): catalog retail-price
 * observer batching/rollback semantics, job uniqueness, and snapshot
 * refresh. Also covers §3 job дугаар (tries/timeout/failOnTimeout),
 * chunked full refresh of published-only products, and the prune
 * schedule entry (daily 05:10 Cairo).
 */
class TaskTxtRetailPriceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
    }

    private static int $seq = 0;

    private function variant(float $retail = 400): ProductVariant
    {
        $n = 73000 + (++self::$seq);
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P'.$n,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail,
        ]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'P'.$n.'-BLK',
            'retail_price' => $retail, 'wholesale_price' => 300, 'cost_price' => 50, 'is_active' => true,
        ]);
    }

    /**
     * The test transaction swallows afterCommit timing, and Queue::fake
     * records creation-time dispatches too — so commit/rollback semantics
     * run in a real-commit sandbox: commit the outer test transaction
     * (both seeders are idempotent), fake the queue, run a top-level
     * transaction, then delete fixtures and reopen a transaction for
     * teardown. Returns a cleanup closure.
     */
    private function realCommitSandbox(bool $fakeQueue = true): \Closure
    {
        DB::commit();
        if ($fakeQueue) {
            Queue::fake();
        }
        $models = [];

        return function (array $collect) use (&$models): void {
            foreach (array_merge($models, $collect) as $model) {
                $model->exists && $model->forceDelete();
            }
            DB::beginTransaction();
        };
    }

    // ── (a) update inside tx + commit → exactly one job ──
    public function test_task25a_commit_dispatches_one_job(): void
    {
        $variant = $this->variant();
        $finish = $this->realCommitSandbox();

        DB::transaction(function () use ($variant): void {
            $variant->forceFill(['retail_price' => 450])->save();
        });

        Queue::assertPushed(RefreshCatalogRetailPrices::class, 1);
        Queue::assertPushed(RefreshCatalogRetailPrices::class, fn ($job) => ((array) $job->productId) === [$variant->product_id]);
        $finish([$variant, $variant->product, $variant->product->category]);
    }

    // ── (b) rollback → no job ──
    // NOTE: Queue::fake records dispatches bypassing the afterCommit gate,
    // so the rollback guarantee is verified by EFFECT: with the real sync
    // driver a dispatched job would stamp catalog_price_refreshed_at.
    public function test_task25b_rollback_dispatches_no_job(): void
    {
        $finish = $this->realCommitSandbox(false);
        $variant = $this->variant();
        $product = $variant->product;
        $product->forceFill(['catalog_price_refreshed_at' => null, 'catalog_min_retail_price' => null])->save();

        try {
            DB::transaction(function () use ($variant): void {
                $variant->forceFill(['retail_price' => 450])->save();

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(400.0, (float) $variant->fresh()->retail_price);
        $this->assertNull($product->fresh()->catalog_price_refreshed_at);
        $finish([$variant, $product, $product->category]);
    }

    // ── (c) batch() with a nested rollback drops rolled-back ids ──
    public function test_task25c_batch_nested_rollback_drops_ids(): void
    {
        $observer = app(CatalogRetailPriceObserver::class);
        $v1 = $this->variant();
        $v2 = $this->variant();
        $finish = $this->realCommitSandbox();

        $observer->batch(function () use ($v1, $v2): void {
            DB::transaction(function () use ($v1): void {
                $v1->forceFill(['retail_price' => 451])->save();
            });
            try {
                DB::transaction(function () use ($v2): void {
                    $v2->forceFill(['retail_price' => 452])->save();

                    throw new \RuntimeException('inner boom');
                });
            } catch (\RuntimeException) {
            }
        });

        Queue::assertPushed(RefreshCatalogRetailPrices::class, 1);
        Queue::assertPushed(RefreshCatalogRetailPrices::class, function ($job) use ($v1): bool {
            $ids = (array) ($job->productId ?? []);
            sort($ids);

            return $ids === [$v1->product_id];
        });
        $finish([$v1, $v2, $v1->product, $v2->product, $v1->product->category]);
    }

    // ── (d) product_id change refreshes old + new products ──
    public function test_task25d_product_move_refreshes_both_products(): void
    {
        $v = $this->variant();
        $oldProductId = $v->product_id;
        $oldProduct = $v->product;
        $cat = Category::factory()->create(['is_active' => true]);
        $newProduct = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P73999',
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail,
        ]);
        $finish = $this->realCommitSandbox();

        DB::transaction(function () use ($v, $newProduct): void {
            $v->forceFill(['product_id' => $newProduct->id])->save();
        });

        Queue::assertPushed(RefreshCatalogRetailPrices::class, function ($job) use ($oldProductId, $newProduct): bool {
            $ids = (array) ($job->productId ?? []);
            sort($ids);
            $expected = [$oldProductId, $newProduct->id];
            sort($expected);

            return $ids === $expected;
        });
        $finish([$v, $oldProduct, $newProduct, $oldProduct->category, $cat]);
    }

    // ── (e) job run updates snapshot columns ──
    public function test_task25e_job_updates_snapshot_columns(): void
    {
        $variant = $this->variant(450);
        $product = $variant->product;
        $product->forceFill(['status' => ProductStatus::Published, 'catalog_min_retail_price' => null, 'catalog_price_refreshed_at' => null])->save();

        (new RefreshCatalogRetailPrices($product->id))->handle(app(\App\Services\Catalog\RetailPriceSnapshot::class));

        $fresh = $product->fresh();
        $this->assertSame(450.0, (float) $fresh->catalog_min_retail_price);
        $this->assertNotNull($fresh->catalog_price_refreshed_at);
    }

    // ── uniqueId stable regardless of order ──
    public function test_task25f_unique_id_order_independent(): void
    {
        $a = new RefreshCatalogRetailPrices([3, 1, 2]);
        $b = new RefreshCatalogRetailPrices([2, 3, 1, 2]);
        $this->assertSame($a->uniqueId(), $b->uniqueId());
        $this->assertNotSame($a->uniqueId(), (new RefreshCatalogRetailPrices([1, 2]))->uniqueId());
        $this->assertSame('catalog-retail:all', (new RefreshCatalogRetailPrices(null))->uniqueId());
    }

    // ── §3: job hardening flags + full published-only refresh in 200-chunks ──
    public function test_task25g_job_hardening_and_full_refresh_scope(): void
    {
        // Suppress observer sync-runs during fixtures (sync queue driver),
        // so only the explicit full refresh below touches snapshots.
        Queue::fake();
        $job = new RefreshCatalogRetailPrices(null);
        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertTrue($job->failOnTimeout);

        $pubRetail = $this->variant(500);
        $pubRetail->product->forceFill(['status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Retail])->save();
        $pubBoth = $this->variant(600);
        $pubBoth->product->forceFill(['status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Both])->save();
        $pubWholesale = $this->variant(700);
        $pubWholesale->product->forceFill(['status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Wholesale])->save();
        $draft = $this->variant(800);

        (new RefreshCatalogRetailPrices(null))->handle(app(\App\Services\Catalog\RetailPriceSnapshot::class));

        // Full refresh covers published retail/both only.
        $this->assertNotNull($pubRetail->product->fresh()->catalog_price_refreshed_at);
        $this->assertNotNull($pubBoth->product->fresh()->catalog_price_refreshed_at);
        $this->assertNull($pubWholesale->product->fresh()->catalog_price_refreshed_at);
        $this->assertNull($draft->product->fresh()->catalog_price_refreshed_at);
    }

    // ── §3: prune scheduled daily 05:10 Cairo ──
    public function test_task25h_prune_scheduled_daily_0510_cairo(): void
    {
        $events = collect(\Illuminate\Support\Facades\Schedule::events());
        $prune = $events->first(fn ($e) => str_contains((string) $e->command, 'ai:prune-conversations'));
        $this->assertNotNull($prune, 'ai:prune-conversations missing from schedule');
        $this->assertSame('05:10', $prune->expression === '* * * * *' ? null : $this->cronTime($prune->expression));
        $this->assertSame('Africa/Cairo', $prune->timezone);
    }

    private function cronTime(string $expression): ?string
    {
        $parts = preg_split('/\s+/', trim($expression));

        return count($parts) >= 2 ? str_pad($parts[1], 2, '0', STR_PAD_LEFT).':'.str_pad($parts[0], 2, '0', STR_PAD_LEFT) : null;
    }
}
