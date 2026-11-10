<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Location;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Pos\PosService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/**
 * task.txt الدفعة الرابعة (POS): إسناد الورديات (19)، والبحث عن الفواتير (20).
 * معاملات فقط بلا DDL.
 */
class PosReturnTaskTxtBatchDTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function refundToken(User $user): string
    {
        return $this->tokenFor($user, ['pos.sales.refund']);
    }

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    private function giveStockIn(int $branchId, string $sku, float $qty): void
    {
        $variant = ProductVariant::query()->where('sku', $sku)->firstOrFail();
        $location = Location::factory()->create(['branch_id' => $branchId]);
        StockLevel::query()->forceCreate([
            'branch_id' => $branchId, 'location_id' => $location->id,
            'product_variant_id' => $variant->id, 'on_hand' => $qty, 'reserved' => 0,
        ]);
    }

    /** فاتورة نقدية بكمية معلومة برقم طلب مخصص. */
    private function numberedSale(string $token, int $qty, float $cash, string $number): int
    {
        $saleId = $this->as($token)->postJson('/api/v1/pos/sales', $this->salePayload($cash, 0, $qty), $this->idem())
            ->assertStatus(201)->json('data.id');
        Order::query()->whereKey($saleId)->update(['order_number' => $number]);

        return $saleId;
    }

    private function lookup(string $token, string $number): TestResponse
    {
        return $this->as($token)->getJson('/api/v1/pos/sales/lookup?'.http_build_query(['number' => $number]));
    }

    /**
     * task.txt (20): البحث يفضل فاتورة فرع الكاشير على الأحدث عالميًا —
     * فاتورة أقدم في فرعه تُعرض بدل 403 على فاتورة فرع آخر.
     */
    public function test_lookup_prefers_cashier_branch_over_global_latest(): void
    {
        $branchB = Branch::factory()->create();
        $cashierB = $this->makeCashier('other@example.test', $branchB->id, ['pos.access']);
        $drawerB = Treasury::create([
            'name' => 'Branch B drawer', 'code' => 'DRW-LOOKUP-B',
            'branch_id' => $branchB->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $tokenA = $this->refundToken($this->cashier);
        $tokenB = $this->refundToken($cashierB);
        $this->openShift($cashierB, $drawerB, 0);
        $this->giveStockIn($branchB->id, $this->variant->sku, 5);

        $saleA = $this->numberedSale($tokenA, 1, 100, 'POS-A-123');
        $this->numberedSale($tokenB, 1, 100, 'POS-B-123');

        // قبل الإصلاح: يجد فاتورة الفرع B الأحدث ثم 403. بعده: فاتورة فرعه.
        $this->lookup($tokenA, '123')->assertOk()->assertJsonPath('data.id', $saleA);
    }

    /**
     * task.txt (20): حرفية %_ في البحث، والمدخل الفارغ، وإخفاء فواتير المرتجع.
     */
    public function test_lookup_literal_wildcards_empty_and_hidden_returns(): void
    {
        $token = $this->refundToken($this->cashier);
        $saleId = $this->numberedSale($token, 2, 200, 'POS-12_345');

        // % و_ حرفية: '_' تطابق '_' ولا تطابقها '%'.
        $this->lookup($token, '12_345')->assertOk()->assertJsonPath('data.id', $saleId);
        $this->lookup($token, '12%345')->assertNotFound();

        // مدخل فارغ أو مسافات فقط: 422 من required قبل أي تطبيع
        // (فرع الـ404 للمدخل المطبَّع-فارغ دفاعي لا يُبلغ عبر HTTP).
        $this->lookup($token, '   ')->assertUnprocessable();
        $this->as($token)->getJson('/api/v1/pos/sales/lookup?number=')->assertUnprocessable();

        // إرجاع كامل: البحث يعرض المتبقي صفرًا، ورقم المرتجع مخفي (404)،
        // وتنفيذ إرجاع ثانٍ مرفوض (422).
        $this->as($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 2]],
        ])->assertOk();
        $this->lookup($token, '12_345')->assertOk()->assertJsonPath('data.items.0.returnable_qty', 0);
        $returnNumber = Order::query()->where('return_of_order_id', $saleId)->value('order_number');
        $this->lookup($token, (string) $returnNumber)->assertNotFound();
        $this->as($token)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'كمية المرتجع (1) تتجاوز المتبقي القابل للإرجاع (0).']);
    }

    /**
     * task.txt (19): مرتجع فاتورة وردية سابقة يُنسب لدرج الوردية الحالية،
     * وإغلاق الوردية يرفض النقدية السالبة واللانهائية.
     */
    public function test_return_uses_current_shift_drawer_and_close_validates_cash(): void
    {
        $cashierB = $this->makeCashier('second@example.test', $this->branch->id, ['pos.access']);
        $drawerB = Treasury::create([
            'name' => 'Second drawer', 'code' => 'DRW-CROSS-SHIFT',
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $tokenA = $this->refundToken($this->cashier);
        $tokenB = $this->refundToken($cashierB);
        // تمويل درج B حتى يغطي الرد النقدي — محل الاختبار هو الإسناد لا الخزينة.
        $this->openShift($cashierB, $drawerB, 100);

        $saleId = $this->numberedSale($tokenA, 2, 200, 'POS-CROSS-1');
        $shiftB = PosShift::query()->where('treasury_id', $drawerB->id)->firstOrFail();

        // المرتجع عبر وردية B يُسجَّل عليها.
        $this->as($tokenB)->postJson("/api/v1/pos/sales/{$saleId}/return", [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertOk();
        $returnOrder = Order::query()->where('return_of_order_id', $saleId)->firstOrFail();
        $this->assertSame($shiftB->id, (int) $returnOrder->pos_shift_id);

        $totalsB = app(PosService::class)->computeShiftTotals($shiftB->fresh());
        $this->assertSame(100.0, $totalsB['cash_returns_total']);
        $shiftA = PosShift::query()->where('treasury_id', $this->drawer->id)->firstOrFail();
        $totalsA = app(PosService::class)->computeShiftTotals($shiftA->fresh());
        $this->assertSame(0.0, $totalsA['cash_returns_total']);

        // نقدية فعلية سالبة أو لانهائية مرفوضة.
        foreach ([-5.0, INF] as $actual) {
            try {
                app(PosService::class)->closeShift($shiftB->fresh(), $actual, null);
                $this->fail('إغلاق الوردية بنقدية غير صالحة يجب أن يُرفض.');
            } catch (BusinessException $e) {
                $this->assertSame('النقدية الفعلية غير صالحة.', $e->getMessage());
            }
        }
    }

    public function test_api_sale_lookup_return_exchange_and_close_reconcile_the_drawer(): void
    {
        Treasury::create([
            'name' => 'Branch B safe', 'code' => 'SAFE-POS-JOURNEY', 'branch_id' => $this->branchB->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => 1000, 'is_active' => true,
        ]);
        $this->openShift($this->cashierB, $this->drawerB, 500);
        $token = $this->refundToken($this->cashierB);
        $refundVariant = $this->makeVariant(150);
        $replacement = $this->makeVariant(100);
        $this->stockIn($this->branchB->id, $this->variant, 12);
        $this->stockIn($this->branchB->id, $refundVariant, 2);
        $this->stockIn($this->branchB->id, $replacement, 1);

        $cashSale = $this->as($token)->postJson('/api/v1/pos/sales', $this->salePayload(1000, 0, 10), $this->idem())
            ->assertCreated()->json('data');
        $mixedPayload = $this->salePayload(300, 200, 2);
        $mixedPayload['items'][] = ['product_variant_id' => $refundVariant->id, 'qty' => 2];
        $mixedSale = $this->postJson('/api/v1/pos/sales', $mixedPayload, $this->idem())
            ->assertCreated()->json('data');

        $this->lookup($token, $mixedSale['order_number'])->assertOk()->assertJsonPath('data.id', $mixedSale['id']);
        $this->postJson("/api/v1/pos/sales/{$mixedSale['id']}/return", [
            'items' => [['product_variant_id' => $refundVariant->id, 'qty' => 1]],
        ])->assertOk()->assertJsonPath('data.refund_amount', 150);

        $shift = PosShift::query()->where('treasury_id', $this->drawerB->id)->firstOrFail();
        $expense = app(PosService::class)->recordExpense($shift, ['category' => 'Supplies', 'amount' => 50], $this->cashierB->id);
        $expense->replicate()->fill([
            'expense_number' => 'POS-JOURNEY-VOID',
            'amount' => 25,
            'voided_at' => now(),
            'voided_by' => $this->cashierB->id,
            'void_reason' => 'Duplicate expense',
        ])->save();

        $this->getJson('/api/v1/pos/shifts/current/summary')->assertOk()
            ->assertJsonPath('data.cash_sales_total', '1300.00')
            ->assertJsonPath('data.card_sales_total', '200.00')
            ->assertJsonPath('data.cash_returns_total', '150.00')
            ->assertJsonPath('data.cash_expenses_total', '50.00')
            ->assertJsonPath('data.expected_closing_cash', '1600.00');

        $exchangeOrderId = $this->postJson("/api/v1/pos/sales/{$cashSale['id']}/exchange", [
            'returned_items' => [['product_variant_id' => $this->variant->id, 'qty' => 1]],
            'exchange_items' => [['product_variant_id' => $replacement->id, 'qty' => 1]],
            'cash_amount' => 0,
            'card_amount' => 100,
        ])->assertOk()->json('data.exchange_order_id');
        $this->assertDatabaseHas('orders', [
            'id' => $exchangeOrderId, 'pos_shift_id' => $shift->id, 'card_amount' => 100,
        ]);
        $this->assertSame('1500.00', $this->drawerB->fresh()->current_balance);

        $this->postJson('/api/v1/pos/shifts/close', ['actual_closing_cash' => 1500])->assertOk()
            ->assertJsonPath('data.status', 'closed_pending_approval')
            ->assertJsonPath('data.card_sales_total', '300.00')
            ->assertJsonPath('data.cash_returns_total', '250.00')
            ->assertJsonPath('data.expected_closing_cash', '1500.00')
            ->assertJsonPath('data.cash_difference', '0.00');
        $this->getJson('/api/v1/pos/shifts/current')->assertOk()->assertJsonPath('has_open_shift', false);
    }
}
