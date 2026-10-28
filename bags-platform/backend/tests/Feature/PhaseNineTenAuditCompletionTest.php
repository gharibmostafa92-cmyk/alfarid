<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ExercisesHistoricalResale;
use Tests\TestCase;

/**
 * إغلاق ما تبقى من تدقيق المرحلتين 9–10 (task.txt):
 * H1 رد لطلب آجل غير مدفوع، L4 إعادة فحص نشاط فرع الاستلام لحظة
 * استلام أمر الشراء، L6 فهرس كشف حساب المورد (supplier_id, payment_date).
 */
class PhaseNineTenAuditCompletionTest extends TestCase
{
    use ExercisesHistoricalResale;
    use LazilyRefreshDatabase;

    private User $admin;

    private string $token;

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        return parent::postJson($uri, $data, ['Idempotency-Key' => (string) Str::uuid(), ...$headers], $options);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->token = $this->admin->createToken('t')->plainTextToken;
    }

    private function auth(): static
    {
        return $this->withToken($this->token);
    }

    public function test_refund_is_rejected_for_unpaid_credit_order(): void
    {
        // طلب جملة آجل سُلّم وبقي unpaid: الرد النقدي مرفوض حتى لا يخرج
        // مال مقابل بضاعة لم تُدفع (H1).
        $order = Order::factory()->create([
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => 'delivered',
        ]);
        $return = ReturnRequest::factory()->create([
            'order_id' => $order->id,
            'status' => 'received',
            'total_refund_amount' => 100,
        ]);

        $this->auth()->postJson("/api/v1/admin/returns/{$return->id}/refund", [
            'refund_method' => 'cash',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('return_requests', [
            'id' => $return->id, 'status' => 'received', 'refunded_at' => null,
        ]);
    }

    public function test_purchase_receipt_is_rejected_when_receiving_branch_was_deactivated(): void
    {
        $this->exerciseHistoricalResale();
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $variant = $product->variants()->first() ?? $product->variants()->create([
            'sku' => 'L4-1', 'cost_price' => 100, 'retail_price' => 200,
        ]);

        $po = $this->auth()->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'items' => [[
                'product_variant_id' => $variant->id,
                'ordered_qty' => 5, 'unit_cost' => 90, 'tax_rate' => 0,
            ]],
        ])->assertCreated()->json('data');
        // الاعتماد من مدير آخر: الاعتماد الذاتي مرفوض افتراضيًا (M5)
        $approver = User::factory()->create();
        $approver->assignRole('Super Admin');
        $approverToken = $approver->createToken('approver')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($approverToken)->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")->assertOk();
        Auth::forgetGuards();
        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/send")->assertOk();

        // الفرع عُطّل بعد الإرسال: الاستلام يجب أن يُرفض بلا أي حركة مخزون
        $branch->update(['is_active' => false]);

        $this->auth()->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => 5]],
        ])->assertUnprocessable();

        $this->assertSame(0, StockMovement::query()->where('reference_type', 'purchase_order')->count());
        $this->assertDatabaseHas('purchase_orders', ['id' => $po['id'], 'status' => 'sent_to_supplier']);
    }

    public function test_supplier_payments_statement_index_exists(): void
    {
        $exists = DB::table('pg_indexes')
            ->where('tablename', 'supplier_payments')
            ->where('indexname', 'supplier_payments_supplier_date_index')
            ->exists();

        $this->assertTrue($exists, 'فهرس كشف الحساب (supplier_id, payment_date) مفقود.');
    }
}
