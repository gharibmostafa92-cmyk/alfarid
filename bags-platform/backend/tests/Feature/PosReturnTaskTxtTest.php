<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Inventory\StockMovementType;
use App\Enums\Finance\TreasuryTransactionType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\RunsConcurrentAuditJobs;

/**
 * task.txt (1) و(2): مرتجعات POS المكررة والمتزامنة.
 *
 * (1) توثيق سلوك حالي بانتظار قرار صاحب المشروع: لا يوجد مفتاح idempotency
 * على مسار المرتجع، فالطلب الثاني يُسجَّل مرتجعًا جديدًا بخصم خزينة مكرر.
 * (2) القفل على الطلب الأصلي يمنع تجاوز المتبقي تحت التزامن.
 */
class PosReturnTaskTxtTest extends PosScenarioFixture
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;


    private function refundToken(User $user): string
    {
        return $this->tokenFor($user, ['pos.sales.refund']);
    }

    /**
     * طلب HTTP بهوية جديدة: يعيد ضبط حراس المصادقة قبل كل هوية مختلفة،
     * لأن singleton الحراس يبقى بين الطلبات داخل نفس العملية.
     */
    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    /** بيع نقدي مسلَّم بكمية معلومة. */
    private function cashSale(string $token, int $qty, float $cash): int
    {
        return $this->as($token)->postJson(
            '/api/v1/pos/sales',
            $this->salePayload($cash, 0, $qty),
            $this->idem()
        )->assertStatus(201)->json('data.id');
    }

    private function returnPayload(int $qty): array
    {
        return ['items' => [['product_variant_id' => $this->variant->id, 'qty' => $qty]]];
    }

    private function returnUrl(int $saleId): string
    {
        return "/api/v1/pos/sales/{$saleId}/return";
    }

    public function test_double_return_requests_record_two_returns_pending_owner_decision(): void
    {
        $token = $this->refundToken($this->cashier);
        $saleId = $this->cashSale($token, 3, 300);

        // إرسال متتابع لنفس المرتجع الجزئي (قطعة واحدة من 3).
        $this->as($token)->postJson($this->returnUrl($saleId), $this->returnPayload(1))->assertOk();
        $this->as($token)->postJson($this->returnUrl($saleId), $this->returnPayload(1))->assertOk();

        // السلوك الحالي (بانتظار قرار صاحب المشروع): أمران مرتجعان وخصم خزينة مزدوج.
        $this->assertSame(2, Order::query()->where('return_of_order_id', $saleId)->count());
        $this->assertSame(2, TreasuryTransaction::query()
            ->where('type', TreasuryTransactionType::PosReturn->value)->count());
        $this->assertSame(2.0, (float) OrderItem::query()->where('order_id', $saleId)->sum('returned_qty'));

        // وإعادة الإرسال المتزامنة على المتبقي (قطعة واحدة) يقبل واحدًا فقط.
        $job = $this->httpJob($token, 'POST', $this->returnUrl($saleId), $this->returnPayload(1));
        $results = $this->concurrentJobs([$job, $job]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'status'));
        $this->assertSame(3.0, (float) OrderItem::query()->where('order_id', $saleId)->sum('returned_qty'));
    }

    public function test_concurrent_returns_beyond_remaining_leave_one_winner(): void
    {
        $secondCashier = $this->makeCashier('second@example.test', $this->branch->id, ['pos.access']);
        $secondDrawer = Treasury::create([
            'name' => 'Second drawer', 'code' => 'DRW-PARALLEL-2',
            'branch_id' => $this->branch->id, 'type' => \App\Enums\TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $tokenA = $this->refundToken($this->cashier);
        $tokenB = $this->refundToken($secondCashier);
        // كلا الدرجين مموَّل نقدًا حتى يتنافس الطلبان على قيد الكمية فقط،
        // لا على رصيد الخزينة (وإلا فاز «رصيد غير كافٍ» قبل فحص المتبقي).
        $this->openShift($secondCashier, $secondDrawer, 200);

        // فاتورة فيها قطعتان؛ مرتجعان متزامنان من كاشيرين مجموعهما 4.
        $saleId = $this->cashSale($tokenA, 2, 200);

        $results = $this->concurrentJobs([
            $this->httpJob($tokenA, 'POST', $this->returnUrl($saleId), $this->returnPayload(2)),
            $this->httpJob($tokenB, 'POST', $this->returnUrl($saleId), $this->returnPayload(2)),
        ]);

        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'status'));
        $loser = collect($results)->firstWhere('status', 422);
        $this->assertStringContainsString('تتجاوز المتبقي', (string) json_encode($loser['body'], JSON_UNESCAPED_UNICODE));

        $this->assertSame(2.0, (float) OrderItem::query()->where('order_id', $saleId)->sum('returned_qty'));
        // ملاحظة: recordMovement يخزّن اسم morph المختصر لا FQCN، كما في استعلامات PosService نفسها.
        $this->assertSame(2.0, (float) StockMovement::query()
            ->whereIn('reference_type', [(new Order)->getMorphClass(), Order::class])
            ->where('reference_id', $saleId)
            ->where('type', StockMovementType::SaleReturn)
            ->sum('quantity'));
    }
}
