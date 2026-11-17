<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Procurement\PurchaseOrderStatus;
use App\Events\Procurement\PurchaseOrderSentToSupplier;
use App\Listeners\Procurement\SendPurchaseOrderSent;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\Procurement\SupplierFinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ExercisesHistoricalResale;
use Tests\TestCase;

class Task2Slice2ProcurementSecurityAndStatementTest extends TestCase
{
    use ExercisesHistoricalResale;
    use LazilyRefreshDatabase;

    private User $centralAdmin;

    private User $branchAdmin;

    private Branch $branch;

    private Supplier $supplier;

    private SupplierFinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exerciseHistoricalResale();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->centralAdmin = User::factory()->create(['branch_id' => null]);
        $this->centralAdmin->assignRole('Super Admin');

        $this->branch = Branch::query()->create(['name' => 'Branch B1', 'code' => 'BR-B1', 'is_active' => true]);

        $this->branchAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->branchAdmin->givePermissionTo([
            'suppliers.view',
            'suppliers.create',
            'suppliers.update',
            'suppliers.delete',
        ]);

        $this->supplier = Supplier::query()->create([
            'code' => 'SUP-SEC-1',
            'name' => 'Security Test Supplier',
            'payment_terms_days' => 30,
            'is_active' => true,
            'opening_balance' => '100.00',
            'current_balance' => '100.00',
        ]);

        $this->finance = app(SupplierFinanceService::class);
    }

    public function test_supplier_controller_enforces_central_access_on_management(): void
    {
        $token = $this->branchAdmin->createToken('branch-t')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/suppliers', [
                'code' => 'SUP-NEW',
                'name' => 'New Sup',
                'payment_terms_days' => 15,
            ])
            ->assertForbidden();

        $this->withToken($token)
            ->putJson("/api/v1/admin/suppliers/{$this->supplier->id}", [
                'code' => 'SUP-SEC-1',
                'name' => 'Updated Sup',
                'payment_terms_days' => 15,
            ])
            ->assertForbidden();

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/suppliers/{$this->supplier->id}")
            ->assertForbidden();

        $this->withToken($token)
            ->postJson("/api/v1/admin/suppliers/{$this->supplier->id}/users", [
                'name' => 'Portal User',
                'email' => 'user@supplier.com',
                'password' => 'secret123456',
                'password_confirmation' => 'secret123456',
            ])
            ->assertForbidden();
    }

    public function test_store_user_rejects_creating_active_user_for_inactive_supplier(): void
    {
        $this->supplier->update(['is_active' => false]);
        $token = $this->centralAdmin->createToken('central-t')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/admin/suppliers/{$this->supplier->id}/users", [
                'name' => 'Inactive Sup User',
                'email' => 'inact@supplier.com',
                'password' => 'secret123456',
                'password_confirmation' => 'secret123456',
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن إنشاء أو تفعيل مستخدم لمورد معطل.');
    }

    public function test_build_statement_snapshot_reloads_supplier_in_snapshot(): void
    {
        $snapshot = $this->finance->statement($this->supplier);
        $this->assertSame('100.00', $snapshot['opening_balance']);
        $this->assertSame('100.00', $snapshot['supplier']['current_balance']);
    }

    public function test_send_purchase_order_sent_skips_cancelled_order(): void
    {
        Notification::fake();

        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-SENT-1',
            'supplier_id' => $this->supplier->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->centralAdmin->id,
            'status' => PurchaseOrderStatus::Cancelled,
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'discount_amount' => '0.00',
            'grand_total' => '0.00',
        ]);

        $supplierUser = SupplierUser::query()->create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Supplier User',
            'email' => 'supuser@test.com',
            'password' => 'secret123',
            'is_active' => true,
        ]);

        $event = new PurchaseOrderSentToSupplier($po);
        (new SendPurchaseOrderSent)->handle($event);

        Notification::assertNothingSent();
    }
}
