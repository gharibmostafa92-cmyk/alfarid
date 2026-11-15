<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Customer;
use App\Models\User;
use App\Models\WalletAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class Task2Slice4SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('finance.reports.view', 'staff');
        Permission::findOrCreate('reports.view', 'staff');
        Permission::findOrCreate('inventory.all_branches', 'staff');
        Permission::findOrCreate('customers.wallet.view', 'staff');
        Permission::findOrCreate('customers.wallet.adjust', 'staff');
    }

    public function test_critical_01_report_and_dashboard_controllers_enforce_has_middleware_permission(): void
    {
        $plainStaff = User::factory()->create(['is_active' => true, 'is_system' => false]);
        $plainStaff->givePermissionTo('inventory.all_branches');
        $plainToken = $plainStaff->createToken('plain')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($plainToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($plainToken)
            ->getJson('/api/v1/admin/reports/sales')
            ->assertForbidden();

        // Staff with finance.reports.view gets access
        $financeStaff = User::factory()->create(['is_active' => true, 'is_system' => false]);
        $financeStaff->givePermissionTo(['finance.reports.view', 'inventory.all_branches']);
        $financeToken = $financeStaff->createToken('finance')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($financeToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($financeToken)
            ->getJson('/api/v1/admin/reports/sales')
            ->assertOk();

        // Staff with reports.view also gets access via unified gate
        $reportStaff = User::factory()->create(['is_active' => true, 'is_system' => false]);
        $reportStaff->givePermissionTo(['reports.view', 'inventory.all_branches']);
        $reportToken = $reportStaff->createToken('reports')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($reportToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($reportToken)
            ->getJson('/api/v1/admin/reports/sales')
            ->assertOk();
    }

    public function test_high_03_customer_wallet_controller_supports_large_amounts_and_reversal_reason(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_system' => false]);
        $admin->givePermissionTo(['customers.wallet.view', 'customers.wallet.adjust']);
        $token = $admin->createToken('admin')->plainTextToken;

        $customer = Customer::factory()->create(['wallet_balance' => '0.00']);

        // Amount exceeding old 99,999,999 limit (e.g. 150,000,000.00) is now accepted
        Auth::forgetGuards();
        $adjustResponse = $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'adj-test-large-1'])
            ->postJson("/api/v1/admin/customers/{$customer->id}/wallet/adjustments", [
                'kind' => 'adjustment',
                'amount' => '150000000.00',
                'direction' => 'credit',
                'reason' => 'تسوية رصيد افتتاح تاجر جملة',
            ])
            ->assertCreated();

        $adjustmentId = (int) $adjustResponse->json('data.id');
        $this->assertSame('150000000.00', (string) $customer->fresh()->wallet_balance);

        // Missing Idempotency-Key on reverse returns 422
        $this->flushHeaders();
        Auth::forgetGuards();
        $this->withToken($token)
            ->deleteJson("/api/v1/admin/wallet-adjustments/{$adjustmentId}", [
                'reason' => 'تم الإلغاء بطلب من الإدارة',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'مفتاح عدم التكرار مطلوب لعكس التعديل.');

        // Reversal with custom reason and Idempotency-Key succeeds
        Auth::forgetGuards();
        $reverseResponse = $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'rev-test-large-1'])
            ->deleteJson("/api/v1/admin/wallet-adjustments/{$adjustmentId}", [
                'reason' => 'تم الإلغاء بطلب من الإدارة',
            ])
            ->assertCreated();

        $reverseReason = (string) $reverseResponse->json('data.reason');
        $this->assertStringContainsString('تم الإلغاء بطلب من الإدارة', $reverseReason);
        $this->assertSame('0.00', (string) $customer->fresh()->wallet_balance);

        // Amount exceeding 9999999999.99 is rejected with 422
        Auth::forgetGuards();
        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'adj-test-too-large'])
            ->postJson("/api/v1/admin/customers/{$customer->id}/wallet/adjustments", [
                'kind' => 'adjustment',
                'amount' => '10000000000.00',
                'direction' => 'credit',
                'reason' => 'مبلغ غير صالح',
            ])
            ->assertStatus(422);
    }
}
