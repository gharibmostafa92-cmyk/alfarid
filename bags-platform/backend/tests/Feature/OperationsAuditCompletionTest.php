<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TreasuryType;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\OrderAllocationService;
use Database\Seeders\PromotionPermissionSeeder;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OperationsAuditCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            ReturnPermissionSeeder::class,
            PromotionPermissionSeeder::class,
            WholesalePortalPermissionSeeder::class,
        ]);
    }

    public static function returnActions(): array
    {
        return [['GET'], ['PUT'], ['DELETE']];
    }

    #[DataProvider('returnActions')]
    public function test_return_assigned_elsewhere_cannot_be_accessed_by_the_order_branch(string $method): void
    {
        $orderBranch = Branch::factory()->create();
        $returnBranch = Branch::factory()->create();
        $staff = User::factory()->create(['branch_id' => $orderBranch->id]);
        $staff->givePermissionTo(['returns.view', 'returns.update', 'returns.delete']);
        $return = ReturnRequest::factory()->create([
            'order_id' => Order::factory()->create(['allocated_branch_id' => $orderBranch->id])->id,
            'branch_id' => $returnBranch->id,
        ]);

        $this->withToken($staff->createToken('audit')->plainTextToken);
        $this->getJson('/api/v1/admin/returns')->assertOk()->assertJsonCount(0, 'data');
        $this->json($method, "/api/v1/admin/returns/{$return->id}", ['admin_notes' => 'Unauthorized edit'])
            ->assertForbidden();
        $this->assertDatabaseHas('return_requests', ['id' => $return->id, 'admin_notes' => null]);
    }

    public function test_unassigned_return_remains_visible_to_the_order_branch(): void
    {
        $branch = Branch::factory()->create();
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $staff->givePermissionTo('returns.view');
        $return = ReturnRequest::factory()->create([
            'order_id' => Order::factory()->create(['allocated_branch_id' => $branch->id])->id,
            'branch_id' => null,
        ]);

        $this->withToken($staff->createToken('audit')->plainTextToken)
            ->getJson("/api/v1/admin/returns/{$return->id}")
            ->assertOk()->assertJsonPath('data.id', $return->id);
    }

    public function test_default_payment_notice_treasury_cannot_bypass_staff_branch_access(): void
    {
        $branch = Branch::factory()->create();
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $staff->givePermissionTo('payment_notices.approve');
        $treasury = Treasury::create([
            'name' => 'Central bank', 'code' => 'AUDIT-CENTRAL',
            'type' => TreasuryType::BankAccount, 'branch_id' => null,
            'current_balance' => '0.00', 'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);
        Order::factory()->create([
            'customer_id' => $customer->id, 'payment_method' => 'credit',
            'payment_status' => 'unpaid', 'status' => 'delivered', 'grand_total' => '1000.00',
        ]);
        $notice = WholesalePaymentNotice::factory()->create(['customer_id' => $customer->id, 'amount' => '100.00']);

        $this->withToken($staff->createToken('audit')->plainTextToken)
            ->postJson("/api/v1/admin/payment-notices/{$notice->id}/approve", [])
            ->assertForbidden();
        $this->assertSame('0.00', $treasury->fresh()->current_balance);
        $this->assertSame(WholesalePaymentNoticeStatus::PendingReview, $notice->fresh()->status);
        $this->assertDatabaseCount('treasury_transactions', 0);
    }

    public static function promotionScopes(): array
    {
        return [['categories'], ['brands'], ['variants']];
    }

    #[DataProvider('promotionScopes')]
    public function test_promotion_targets_must_exist_in_the_selected_scope(string $scope): void
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo('promotions.create');

        $this->withToken($staff->createToken('audit')->plainTextToken)
            ->postJson('/api/v1/admin/promotions', [
                'name' => 'Scoped offer', 'type' => 'fixed_discount', 'value' => 10,
                'applies_to' => $scope, 'target_ids' => [2147483647],
            ])->assertUnprocessable()->assertJsonValidationErrors('target_ids');
        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_promotion_with_implicit_all_scope_needs_no_targets(): void
    {
        $staff = User::factory()->create();
        $staff->givePermissionTo('promotions.create');

        $this->withToken($staff->createToken('audit')->plainTextToken)
            ->postJson('/api/v1/admin/promotions', [
                'name' => 'All products offer', 'type' => 'fixed_discount', 'value' => 10,
            ])->assertCreated()->assertJsonPath('data.applies_to', 'all');
    }

    private function authenticateFor(string $permission): void
    {
        Permission::findOrCreate($permission, 'staff');
        $staff = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $staff->givePermissionTo($permission);
        $this->withToken($staff->createToken('audit-validation')->plainTextToken);
    }

    public function test_coupon_codes_are_trimmed_normalized_and_unique_without_case(): void
    {
        $this->authenticateFor('coupons.create');
        $this->postJson('/api/v1/admin/coupons', [
            'code' => ' save10 ', 'type' => 'percentage', 'value' => 10,
        ])->assertCreated()->assertJsonPath('data.code', 'SAVE10');
        $this->postJson('/api/v1/admin/coupons', [
            'code' => 'SaVe10', 'type' => 'fixed', 'value' => 20,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('coupons', 1);
    }

    public function test_legacy_lowercase_coupon_code_is_rejected_before_database_insert(): void
    {
        $this->authenticateFor('coupons.create');
        $coupon = Coupon::factory()->create(['code' => 'LEGACY10']);
        DB::table('coupons')->where('id', $coupon->id)->update(['code' => 'legacy10']);

        $this->postJson('/api/v1/admin/coupons', [
            'code' => 'Legacy10', 'type' => 'fixed', 'value' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('coupons', 1);
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'code' => 'legacy10']);
    }

    public function test_database_coupon_code_constraint_rejects_different_case_without_model_hooks(): void
    {
        Coupon::factory()->create(['code' => 'SAVE10']);
        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('coupons')->insert(['code' => 'save10', 'type' => 'fixed', 'value' => 10]);
    }

    public static function invalidDiscounts(): array
    {
        $cases = [];
        foreach (['coupons' => ['percentage', 'fixed'], 'promotions' => ['percentage_discount', 'fixed_discount']] as $endpoint => $types) {
            foreach ($types as $type) {
                foreach (['missing', 'null', 'invalid'] as $value) {
                    $cases["{$endpoint}:{$type}:{$value}"] = [$endpoint, $type, $value];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('invalidDiscounts')]
    public function test_discount_types_require_a_numeric_non_null_value(string $endpoint, string $type, string $value): void
    {
        $this->authenticateFor("{$endpoint}.create");
        $payload = ['code' => 'VALUE10', 'name' => 'Validated discount', 'type' => $type];
        if ($value !== 'missing') {
            $payload['value'] = $value === 'null' ? null : 'not-a-number';
        }

        $this->postJson("/api/v1/admin/{$endpoint}", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->assertDatabaseCount($endpoint, 0);
    }

    public static function freeShippingEndpoints(): array
    {
        return [['coupons'], ['promotions']];
    }

    #[DataProvider('freeShippingEndpoints')]
    public function test_free_shipping_remains_valid_without_a_discount_value(string $endpoint): void
    {
        $this->authenticateFor("{$endpoint}.create");
        $this->postJson("/api/v1/admin/{$endpoint}", [
            'code' => 'SHIPFREE', 'name' => 'Free shipping', 'type' => 'free_shipping',
        ])->assertCreated()->assertJsonPath('data.type', 'free_shipping');
    }

    public static function validPromotionScopes(): array
    {
        return [['categories', Category::class], ['brands', Brand::class], ['variants', ProductVariant::class]];
    }

    #[DataProvider('validPromotionScopes')]
    public function test_existing_promotion_targets_are_accepted(string $scope, string $model): void
    {
        $this->authenticateFor('promotions.create');
        $target = $model::factory()->create();

        $this->postJson('/api/v1/admin/promotions', [
            'name' => 'Existing target offer', 'type' => 'fixed_discount', 'value' => 10,
            'applies_to' => $scope, 'target_ids' => [$target->id],
        ])->assertCreated()->assertJsonPath('data.target_ids', [$target->id]);
    }

    public function test_promotion_target_arrays_are_bounded_to_500_entries(): void
    {
        $this->authenticateFor('promotions.create');
        $this->postJson('/api/v1/admin/promotions', [
            'name' => 'Oversized target offer', 'type' => 'fixed_discount', 'value' => 10,
            'applies_to' => 'categories', 'target_ids' => range(1, 501),
        ])->assertUnprocessable()->assertJsonValidationErrors('target_ids');
        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_report_accepts_366_inclusive_days_but_rejects_367(): void
    {
        $this->authenticateFor('reports.view');
        $this->getJson('/api/v1/admin/reports/profit?from=2024-01-01&to=2024-12-31')->assertOk();
        $this->getJson('/api/v1/admin/reports/profit?from=2024-01-01&to=2025-01-01')->assertUnprocessable();
    }

    public static function invalidReportPeriods(): array
    {
        return [
            ['from=2026-10-01', 'to'],
            ['to=2026-09-30', 'from'],
            ['from=2026-10-10&to=2026-10-01', 'to'],
            ['from=2026-02-30&to=2026-03-01', 'from'],
        ];
    }

    #[DataProvider('invalidReportPeriods')]
    public function test_report_period_requires_paired_valid_ordered_dates(string $query, string $field): void
    {
        $this->authenticateFor('reports.view');
        $this->getJson("/api/v1/admin/reports/profit?{$query}")
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_malformed_notification_identifier_returns_404(): void
    {
        $staff = User::factory()->create();
        $this->withToken($staff->createToken('audit-notification')->plainTextToken)
            ->postJson('/api/v1/admin/notifications/not-a-uuid/read')->assertNotFound();
    }

    public function test_return_attachment_cannot_bypass_the_assigned_branch(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $staff->givePermissionTo('returns.view');
        $return = ReturnRequest::factory()->create([
            'order_id' => Order::factory()->create(['allocated_branch_id' => $branch->id])->id,
            'branch_id' => $other->id,
        ]);
        $item = ReturnRequestItem::factory()->create([
            'return_request_id' => $return->id, 'attachments' => ['audit/receipt.pdf'],
        ]);
        Storage::disk('r2')->put('audit/receipt.pdf', 'private contents');
        $this->withToken($staff->createToken('audit')->plainTextToken)
            ->getJson("/api/v1/admin/return-items/{$item->id}/attachments/0")->assertForbidden();
    }

    public static function allocationActions(): array
    {
        return [['allocate'], ['deallocate']];
    }

    #[DataProvider('allocationActions')]
    public function test_allocation_rechecks_a_stale_order_status_under_lock(string $action): void
    {
        $order = Order::factory()->create(['status' => 'confirmed']);
        Order::query()->whereKey($order->id)->update(['status' => 'cancelled']);
        $this->expectException(BusinessException::class);
        app(OrderAllocationService::class)->{$action}($order);
    }
}
