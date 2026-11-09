<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TreasuryType;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Concerns\RunsConcurrentAuditJobs;

class ScratchProbeTest extends PosScenarioFixture
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    public function test_probe_drawer_balances(): void
    {
        $secondCashier = $this->makeCashier('second@example.test', $this->branch->id, ['pos.access']);
        $secondDrawer = Treasury::create([
            'name' => 'Second drawer', 'code' => 'DRW-PROBE',
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $tokenA = $this->tokenFor($this->cashier, ['pos.sales.refund']);
        $tokenB = $this->tokenFor($secondCashier, ['pos.sales.refund']);
        $this->openShift($secondCashier, $secondDrawer, 200);

        fwrite(STDERR, "\nDRAWER_A_AFTER_OPEN=".$this->drawer->fresh()->current_balance."\n");
        fwrite(STDERR, "DRAWER_B_AFTER_OPEN=".$secondDrawer->fresh()->current_balance."\n");

        foreach (\Laravel\Sanctum\PersonalAccessToken::all() as $t) {
            fwrite(STDERR, "TOKEN id={$t->id} tokenable={$t->tokenable_type}#{$t->tokenable_id} name={$t->name}\n");
        }
        $saleId = $this->withToken($tokenA)->postJson('/api/v1/pos/sales',
            $this->salePayload(200, 0, 2), $this->idem())->assertStatus(201)->json('data.id');

        fwrite(STDERR, "DRAWER_A_AFTER_SALE=".$this->drawer->fresh()->current_balance."\n");
        fwrite(STDERR, "SALE_ID={$saleId}\n");
        foreach (\App\Models\Treasury::all() as $t) {
            fwrite(STDERR, "TREASURY id={$t->id} name={$t->name} branch={$t->branch_id} balance={$t->current_balance}\n");
        }
        foreach (\App\Models\PosShift::all() as $s) {
            fwrite(STDERR, "SHIFT id={$s->id} user={$s->user_id} treasury={$s->treasury_id} branch={$s->branch_id} status={$s->status->value}\n");
        }
        foreach (\App\Models\User::all() as $u) {
            fwrite(STDERR, "USER id={$u->id} email={$u->email} branch={$u->branch_id}\n");
        }
        $sale = \App\Models\Order::find($saleId);
        fwrite(STDERR, "SALE shift={$sale->pos_shift_id}\n");
        fwrite(STDERR, "SALE cash={$sale->cash_amount} card={$sale->card_amount} grand={$sale->grand_total} method={$sale->payment_method}\n");
        foreach (\App\Models\TreasuryTransaction::all() as $tx) {
            fwrite(STDERR, "TX treasury={$tx->treasury_id} type={$tx->type->value} amount={$tx->amount} after={$tx->balance_after} ref={$tx->reference_type}#{$tx->reference_id}\n");
        }

        $r1 = $this->withToken($tokenA)->postJson("/api/v1/pos/sales/{$saleId}/return",
            ['items' => [['product_variant_id' => $this->variant->id, 'qty' => 2]]]);
        fwrite(STDERR, "RETURN_A_STATUS=".$r1->getStatusCode()." BODY=".substr($r1->getContent(), 0, 300)."\n");
        fwrite(STDERR, "DRAWER_A_AFTER_RET=".$this->drawer->fresh()->current_balance."\n");
        fwrite(STDERR, "DRAWER_B_AFTER_RET=".$secondDrawer->fresh()->current_balance."\n");

        $this->assertTrue(true);
    }
}
