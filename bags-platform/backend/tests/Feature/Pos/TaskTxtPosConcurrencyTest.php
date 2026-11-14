<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Inventory\StockMovementType;
use App\Enums\Finance\TreasuryType;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Treasury;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Concerns\RunsConcurrentAuditJobs;

class TaskTxtPosConcurrencyTest extends PosScenarioFixture
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());

        $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);

        $this->beforeApplicationDestroyed(function () {
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$lazilyRefreshed = false;
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        });
    }

    protected function tearDown(): void
    {
        // Clean up committed data without rolling back the schema
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$lazilyRefreshed = false;
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
    }

    public function test_parallel_idempotent_requests_return_the_same_sale_with_items(): void
    {
        $job = $this->httpJob($this->token, 'POST', '/api/v1/pos/sales',
            $this->salePayload(100, 0), ['HTTP_IDEMPOTENCY_KEY' => 'parallel-sale']);
        $results = $this->concurrentJobs([$job, $job]);

        $this->assertSame([201, 201], array_column($results, 'status'));
        $sale = Order::query()->sole();
        foreach ($results as $result) {
            $this->assertSame($sale->id, $result['body']['data']['id']);
            $this->assertSame($this->variant->id, $result['body']['data']['items'][0]['product_variant_id']);
        }
        $this->assertSame(49.0, (float) StockLevel::query()->where('product_variant_id', $this->variant->id)->sole()->on_hand);
        $this->assertSame(1, StockMovement::query()->where('type', StockMovementType::Sale)->count());
        $this->assertSame('100.00', $this->drawer->fresh()->current_balance);
    }

    public function test_parallel_cashiers_cannot_sell_the_last_piece_twice(): void
    {
        $cashier = $this->makeCashier('parallel@example.test', $this->branch->id, ['pos.access']);
        $secondToken = $this->openShift($cashier, $this->secondDrawer(), 0);
        $lastPiece = $this->makeVariant();
        $this->stockIn($this->branch->id, $lastPiece, 1);
        $payload = $this->salePayload(100, 0, variantId: $lastPiece->id);

        $results = $this->concurrentJobs([
            $this->httpJob($this->token, 'POST', '/api/v1/pos/sales', $payload, ['HTTP_IDEMPOTENCY_KEY' => 'last-piece-first']),
            $this->httpJob($secondToken, 'POST', '/api/v1/pos/sales', $payload, ['HTTP_IDEMPOTENCY_KEY' => 'last-piece-second']),
        ]);

        $this->assertEqualsCanonicalizing([201, 422], array_column($results, 'status'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(0.0, (float) StockLevel::query()->where('product_variant_id', $lastPiece->id)->sole()->on_hand);
        $this->assertSame(1, StockMovement::query()
            ->where('product_variant_id', $lastPiece->id)->where('type', StockMovementType::Sale)->count());
    }

    public function test_parallel_shift_opens_fund_the_drawer_once(): void
    {
        $drawer = $this->secondDrawer();
        $cashierA = $this->makeCashier('opening-a@example.test', $this->branch->id, ['pos.access', 'pos.sales.create']);
        $cashierB = $this->makeCashier('opening-b@example.test', $this->branch->id, ['pos.access', 'pos.sales.create']);
        $payload = ['treasury_id' => $drawer->id, 'opening_cash' => 100];

        $results = $this->concurrentJobs([
            $this->httpJob($this->tokenFor($cashierA), 'POST', '/api/v1/pos/shifts/open', $payload),
            $this->httpJob($this->tokenFor($cashierB), 'POST', '/api/v1/pos/shifts/open', $payload),
        ]);

        $this->assertEqualsCanonicalizing([201, 422], array_column($results, 'status'));
        $this->assertSame(1, PosShift::query()->where('treasury_id', $drawer->id)->count());
        $this->assertSame('100.00', $drawer->fresh()->current_balance);
        $this->assertSame('900.00', $this->safe->fresh()->current_balance);
        // Opening fixture entry plus the winner's transfer pair.
        $this->assertDatabaseCount('treasury_transactions', 3);
    }

    public function test_parallel_resume_consumes_hold_once_and_parallel_sales_dedupe_by_idempotency_key(): void
    {
        $held = $this->withToken($this->token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated();
        $heldId = $held->json('data.id');
        $url = "/api/v1/pos/sales/held/{$heldId}/resume";

        $cashierB = $this->makeCashier('resume-b@example.test', $this->branch->id, ['pos.access', 'pos.sales.create', 'pos.held.view_all']);
        $drawerB = $this->secondDrawer();
        $tokenB = $this->openShift($cashierB, $drawerB, 0);

        $results = $this->concurrentJobs([
            $this->httpJob($this->token, 'POST', $url),
            $this->httpJob($tokenB, 'POST', $url),
        ]);

        // Exactly one resume wins the row lock and acquires the lease; the loser 409s.
        $this->assertEqualsCanonicalizing([200, 409], array_column($results, 'status'));
        $winnerIndex = $results[0]['status'] === 200 ? 0 : 1;
        $winner = $results[$winnerIndex];
        $winnerToken = [$this->token, $tokenB][$winnerIndex];
        $this->assertSame($this->variant->id, $winner['body']['data']['items_payload'][0]['product_variant_id']);
        $this->assertDatabaseHas('pos_held_sales', ['id' => $heldId]);

        // Both sales reference the leased hold and share one Idempotency-Key,
        // so exactly one order is created, the hold is deleted on checkout, and both callers see it.
        $salePayload = [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'cash_amount' => 100.00,
            'held_sale_id' => $heldId,
        ];
        $sharedKey = ['HTTP_IDEMPOTENCY_KEY' => 'parallel-sale-hold'];
        $sales = $this->concurrentJobs([
            $this->httpJob($winnerToken, 'POST', '/api/v1/pos/sales', $salePayload, $sharedKey),
            $this->httpJob($winnerToken, 'POST', '/api/v1/pos/sales', $salePayload, $sharedKey),
        ]);

        $this->assertSame([201, 201], array_column($sales, 'status'));
        $this->assertSame($sales[0]['body']['data']['id'], $sales[1]['body']['data']['id']);
        $this->assertSame(1, Order::query()->count());
        $this->assertDatabaseMissing('pos_held_sales', ['id' => $heldId]);
    }

    private function secondDrawer(): Treasury
    {
        return Treasury::create([
            'name' => 'Second drawer', 'code' => 'DRW-PARALLEL',
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
    }
}
