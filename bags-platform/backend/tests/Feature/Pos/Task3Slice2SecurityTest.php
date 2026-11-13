<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Exceptions\BusinessException;
use App\Models\PosShift;
use App\Services\Pos\PosService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

class Task3Slice2SecurityTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    protected PosShift $shift;

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->shift = PosShift::where('user_id', $this->cashier->id)->firstOrFail();

        // Perform a cash sale so the drawer has balance for expenses
        $this->as($this->token)->postJson('/api/v1/pos/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'qty' => 5]],
            'cash_amount' => 500,
            'card_amount' => 0,
        ], $this->idem())->assertCreated();

        $this->shift->refresh();
        $this->drawer->refresh();
    }

    public function test_record_expense_rejects_path_traversal_attachment(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('مسار مرفق المصروف غير صالح');

        app(PosService::class)->recordExpense($this->shift, [
            'category' => 'نثريات',
            'amount' => 50,
            'attachment_path' => '../../.env',
        ], $this->cashier->id);
    }

    public function test_record_expense_rejects_attachment_outside_allowed_prefixes(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('مسار مرفق المصروف غير صالح');

        app(PosService::class)->recordExpense($this->shift, [
            'category' => 'نثريات',
            'amount' => 50,
            'attachment_path' => 'private-docs/secret.pdf',
        ], $this->cashier->id);
    }

    public function test_record_expense_accepts_valid_attachment(): void
    {
        $expense = app(PosService::class)->recordExpense($this->shift, [
            'category' => 'نثريات',
            'amount' => 50,
            'attachment_path' => 'expenses/2026/receipt.jpg',
        ], $this->cashier->id);

        $this->assertSame('expenses/2026/receipt.jpg', $expense->attachment_path);
    }

    public function test_record_expense_rejects_category_over_100_chars(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('فئة المصروف يجب ألا تتجاوز 100 حرف');

        app(PosService::class)->recordExpense($this->shift, [
            'category' => str_repeat('أ', 101),
            'amount' => 50,
        ], $this->cashier->id);
    }

    public function test_record_expense_rejects_notes_over_500_chars(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('ملاحظات المصروف يجب ألا تتجاوز 500 حرف');

        app(PosService::class)->recordExpense($this->shift, [
            'category' => 'نثريات',
            'amount' => 50,
            'notes' => str_repeat('م', 501),
        ], $this->cashier->id);
    }

    public function test_create_sale_supports_per_line_manual_price_without_corrupting_other_lines(): void
    {
        $token = $this->tokenFor($this->cashier, ['pos.sales.create', 'pos.override_price']);
        $var2 = $this->makeVariant(200.00);
        $this->stockIn($this->branch->id, $var2, 10);

        // Item 1 has manual price 80 (was 100), Item 2 has no unit_price passed (so resolves at 200)
        $res = $this->as($token)->postJson('/api/v1/pos/sales', [
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'qty' => 1,
                    'unit_price' => 80.00,
                    'override_reason' => 'خصم يدوي لسطر محدد',
                ],
                [
                    'product_variant_id' => $var2->id,
                    'qty' => 1,
                ],
            ],
            'override_price' => true,
            'override_reason' => 'خصم عام',
            'payment_method' => 'cash',
            'cash_amount' => 280.00,
            'card_amount' => 0.00,
        ], $this->idem())->assertCreated()->json('data');

        $this->assertSame(280.0, (float) $res['grand_total']);
    }
}
