<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\ProductVariant;
use App\Observers\CatalogRetailPriceObserver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CatalogPriceBatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_failed_batch_does_not_publish_or_leak_product_ids_into_the_next_batch(): void
    {
        $discarded = ProductVariant::factory()->create();
        $first = ProductVariant::factory()->create();
        $second = ProductVariant::factory()->create();
        Bus::fake([RefreshCatalogRetailPrices::class]);
        $observer = app(CatalogRetailPriceObserver::class);

        try {
            $observer->batch(fn () => DB::transaction(function () use ($discarded): void {
                $discarded->update(['retail_price' => '77.00']);
                throw new RuntimeException('rollback');
            }));
            $this->fail('The batch should propagate the rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        Bus::assertNothingDispatched();
        $this->assertNotSame('77.00', $discarded->fresh()->retail_price);

        $observer->batch(fn () => DB::transaction(function () use ($first, $second): void {
            $first->update(['retail_price' => '80.00']);
            $second->update(['retail_price' => '90.00']);
            $first->update(['retail_price' => '85.00']);
        }));
        Bus::assertDispatchedTimes(RefreshCatalogRetailPrices::class, 1);
        Bus::assertDispatched(RefreshCatalogRetailPrices::class, function ($job) use ($first, $second, $discarded): bool {
            $ids = $job->productId;
            $this->assertEqualsCanonicalizing([$first->product_id, $second->product_id], $ids);
            $this->assertNotContains($discarded->product_id, $ids);

            return true;
        });
    }

    public function test_batch_job_identity_ignores_order_and_duplicate_ids(): void
    {
        $this->assertSame(
            (new RefreshCatalogRetailPrices([3, 1, 3]))->uniqueId(),
            (new RefreshCatalogRetailPrices([1, 3]))->uniqueId(),
        );
        $this->assertNotSame(
            (new RefreshCatalogRetailPrices([1, 3]))->uniqueId(),
            (new RefreshCatalogRetailPrices([1, 4]))->uniqueId(),
        );
    }

    public function test_caught_nested_rollback_discards_its_ids_but_retains_committed_inner_changes(): void
    {
        $discarded = ProductVariant::factory()->create();
        $kept = ProductVariant::factory()->create();
        $other = ProductVariant::factory()->create();
        Bus::fake([RefreshCatalogRetailPrices::class]);
        $observer = app(CatalogRetailPriceObserver::class);

        $observer->batch(fn () => DB::transaction(function () use ($observer, $discarded, $kept, $other): void {
            DB::transaction(fn () => $observer->batch(fn () => $kept->update(['retail_price' => '85.00'])));
            try {
                DB::transaction(function () use ($observer, $discarded): void {
                    $observer->batch(fn () => $discarded->update(['retail_price' => '77.00']));
                    throw new RuntimeException('rollback');
                });
            } catch (RuntimeException) {
            }
            $other->update(['retail_price' => '95.00']);
        }));

        Bus::assertDispatchedTimes(RefreshCatalogRetailPrices::class, 1);
        Bus::assertDispatched(RefreshCatalogRetailPrices::class, function ($job) use ($kept, $other, $discarded): bool {
            $this->assertEqualsCanonicalizing([$kept->product_id, $other->product_id], $job->productId);
            $this->assertNotContains($discarded->product_id, $job->productId);

            return true;
        });
    }
}
