<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Services\Pos\PosService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

class Task3Slice4HeldConcurrencyTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    public function test_prune_held_sales_command_succeeds_and_preserves_active_leases(): void
    {
        // 1. Create a stale unleased held sale
        $staleUnleased = PosHeldSale::create([
            'hold_reference' => 'HLD-STALE-1',
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'items_payload' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'created_at' => now()->subDays(10),
            'resumed_at' => null,
        ]);

        // 2. Create a stale held sale with an active lease (resumed 2 minutes ago)
        $staleLeased = PosHeldSale::create([
            'hold_reference' => 'HLD-STALE-2',
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'items_payload' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'created_at' => now()->subDays(10),
            'resumed_at' => now()->subMinutes(2),
        ]);

        // 3. Run artisan command
        $this->artisan('pos:prune-held-sales', ['--days' => 7])->assertSuccessful();

        $this->assertDatabaseMissing('pos_held_sales', ['id' => $staleUnleased->id]);
        $this->assertDatabaseHas('pos_held_sales', ['id' => $staleLeased->id]);
    }

    public function test_delete_held_rejects_with_409_when_actively_resumed_by_another_cashier(): void
    {
        $token1 = $this->tokenFor($this->cashier, ['pos.access']);

        $held = PosHeldSale::create([
            'hold_reference' => 'HLD-ACTIVE-1',
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'items_payload' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'resumed_by' => $this->cashierB->id,
            'resumed_at' => now(), // Active lease by cashier B
        ]);

        $this->as($token1)->deleteJson("/api/v1/pos/sales/held/{$held->id}")
            ->assertStatus(409);
    }

    public function test_bootstrap_held_count_is_filtered_by_user_unless_can_view_all(): void
    {
        // Cashier A creates 2 held sales
        PosHeldSale::create([
            'hold_reference' => 'HLD-A1',
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'items_payload' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ]);
        PosHeldSale::create([
            'hold_reference' => 'HLD-A2',
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'items_payload' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ]);

        // Another cashier in the same branch creates 3 held sales
        $cashierSameBranch = $this->makeCashier('c_same@example.com', $this->branch->id, ['pos.access']);
        for ($i = 1; $i <= 3; $i++) {
            PosHeldSale::create([
                'hold_reference' => "HLD-OTHER-{$i}",
                'branch_id' => $this->branch->id,
                'user_id' => $cashierSameBranch->id,
                'items_payload' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            ]);
        }

        // Cashier A without pos.held.view_all sees only their 2 held sales
        $tokenRestricted = $this->tokenFor($this->cashier, ['pos.access']);
        $resRestricted = $this->as($tokenRestricted)->getJson('/api/v1/pos/bootstrap')->assertOk()->json('data');
        $this->assertSame(2, $resRestricted['held_count']);

        // Cashier A with pos.held.view_all sees all 5 held sales
        $tokenAll = $this->tokenFor($this->cashier, ['pos.access', 'pos.held.view_all']);
        $resAll = $this->as($tokenAll)->getJson('/api/v1/pos/bootstrap')->assertOk()->json('data');
        $this->assertSame(5, $resAll['held_count']);
    }

    public function test_create_sale_consumes_multiple_held_sale_ids_atomically(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.access', 'pos.sales.create']);
        $this->stockIn($this->branch->id, $this->variant, 20);

        $held1 = PosHeldSale::create([
            'hold_reference' => 'HLD-MULTI-1',
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'items_payload' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ]);
        $held2 = PosHeldSale::create([
            'hold_reference' => 'HLD-MULTI-2',
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'items_payload' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ]);

        $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 2],
            ],
            'held_sale_ids' => [$held1->id, $held2->id],
            'payment_method' => 'cash',
            'cash_amount' => 200.0,
            'card_amount' => 0.0,
        ], $this->idem())->assertCreated();

        $this->assertDatabaseMissing('pos_held_sales', ['id' => $held1->id]);
        $this->assertDatabaseMissing('pos_held_sales', ['id' => $held2->id]);
    }
}
