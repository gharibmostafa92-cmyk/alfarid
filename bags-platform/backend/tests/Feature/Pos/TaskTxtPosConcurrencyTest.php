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

    protected function tearDown(): void
    {
        // Older order constraints cannot represent committed POS payment methods.
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
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
        $cashierA = $this->makeCashier('opening-a@example.test', $this->branch->id, ['pos.access']);
        $cashierB = $this->makeCashier('opening-b@example.test', $this->branch->id, ['pos.access']);
        $payload = ['treasury_id' => $drawer->id, 'opening_cash' => 100];

        $results = $this->concurrentJobs([
            $this->httpJob($this->tokenFor($cashierA), 'POST', '/api/v1/pos/shifts/open', $payload),
            $this->httpJob($this->tokenFor($cashierB), 'POST', '/api/v1/pos/shifts/open', $payload),
        ]);

        $this->assertEqualsCanonicalizing([201, 422], array_column($results, 'status'));
        $this->assertSame(1, PosShift::query()->where('treasury_id', $drawer->id)->count());
        $this->assertSame('100.00', $drawer->fresh()->current_balance);
        $this->assertSame('900.00', $this->safe->fresh()->current_balance);
        $this->assertDatabaseCount('treasury_transactions', 2);
    }

    public function test_parallel_cashiers_can_resume_a_held_cart_only_once(): void
    {
        $held = $this->withToken($this->token)->postJson('/api/v1/pos/sales/hold', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated();
        $heldId = $held->json('data.id');
        $cashier = $this->makeCashier('resume@example.test', $this->branch->id, ['pos.access']);
        $secondToken = $this->openShift($cashier, $this->secondDrawer(), 0);
        $url = "/api/v1/pos/sales/held/{$heldId}/resume";

        $results = $this->concurrentJobs([
            $this->httpJob($this->token, 'POST', $url),
            $this->httpJob($secondToken, 'POST', $url),
        ]);

        $this->assertEqualsCanonicalizing([200, 404], array_column($results, 'status'));
        $winner = collect($results)->firstWhere('status', 200);
        $this->assertSame($this->variant->id, $winner['body']['data']['items_payload'][0]['product_variant_id']);
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
