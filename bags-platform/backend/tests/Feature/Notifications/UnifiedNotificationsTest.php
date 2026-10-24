<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Models\User;
use App\Notifications\AdminOrderCreatedNotification;
use App\Notifications\TransferPendingApprovalNotification;
use App\Notifications\WholesaleApplicationSubmittedNotification;
use App\Services\InventoryService;
use App\Services\ProcurementService;
use App\Services\TransferService;
use Database\Seeders\CustomerPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class UnifiedNotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CustomerPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->adminToken = $this->admin->createToken('admin')->plainTextToken;
    }

    private function asAdmin(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->adminToken);
    }

    private function asUser(object $user, string $name = 't'): static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken($name)->plainTextToken);
    }

    public function test_order_created_notifies_customer_and_staff_and_shared_controller_works(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('store')->plainTextToken;
        $city = City::factory()->create(['shipping_cost' => 0]);
        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->update(['retail_price' => 100, 'is_active' => true]);
        StockLevel::forceCreate(['branch_id' => Branch::factory()->create()->id, 'product_variant_id' => $variant->id, 'on_hand' => 10]);

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/store/cart/items', ['product_variant_id' => $variant->id, 'qty' => 1])->assertOk();
        $this->withToken($token)->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id, 'shipping_address_line' => 'عنوان', 'payment_method' => 'cod',
        ])->assertCreated();

        // العميل والإدارة استلما الإشعار
        $this->assertSame(1, $customer->fresh()->notifications()->count());
        $this->assertSame('order_confirmation', $customer->fresh()->notifications()->first()->data['kind']);
        $this->assertSame(1, $this->admin->fresh()->notifications()->where('type', AdminOrderCreatedNotification::class)->count());

        // المتحكم الموحد: قائمة + عدد غير المقروء + تعليم كمقروء
        $list = $this->asUser($customer)->getJson('/api/v1/store/auth/notifications')->assertOk()
            ->assertJsonPath('meta.unread_count', 1);
        $notificationId = $list->json('data.0.id');

        $this->asUser($customer)->postJson("/api/v1/store/auth/notifications/{$notificationId}/read")
            ->assertOk()->assertJsonPath('meta.unread_count', 0);
        $this->assertSame(1, $customer->fresh()->readNotifications()->count());

        // إشعار الإدارة لا يستطيع العميل تعليمه
        $adminNotificationId = $this->admin->fresh()->notifications()->first()->id;
        $this->asUser($customer)->postJson("/api/v1/store/auth/notifications/{$adminNotificationId}/read")->assertNotFound();
    }

    public function test_low_stock_alert_fires_once_when_crossing_reorder_level(): void
    {
        $purchasing = User::factory()->create();
        $purchasing->givePermissionTo('purchases.view');
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['reorder_level' => 5]);
        StockLevel::forceCreate(['branch_id' => $branch->id, 'product_variant_id' => $variant->id, 'on_hand' => 10]);

        $inventory = app(InventoryService::class);
        $move = fn (float $qty) => $inventory->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'type' => 'sale',
            'affected_bucket' => 'on_hand',
            'quantity' => $qty,
            'created_by' => $this->admin->id,
        ]);

        $move(-3); // 10 → 7: فوق الحد، لا تنبيه
        $this->assertSame(0, $purchasing->fresh()->notifications()->count());

        $move(-3); // 7 → 4: عبور الحد نزولًا، تنبيه واحد
        $this->assertSame(1, $purchasing->fresh()->notifications()->count());
        $this->assertSame('low_stock', $purchasing->fresh()->notifications()->first()->data['kind']);

        $move(-1); // 4 → 3: ما زال تحت الحد، لا تكرار
        $this->assertSame(1, $purchasing->fresh()->notifications()->count());
    }

    public function test_transfer_creation_notifies_approvers(): void
    {
        $from = Branch::factory()->create();
        $to = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();

        app(TransferService::class)->create([
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ], $this->admin->id);

        $this->assertSame(1, $this->admin->fresh()->notifications()->where('type', TransferPendingApprovalNotification::class)->count());
    }

    public function test_wholesale_application_submitted_and_rejected_notify_both_sides(): void
    {
        $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'تاجر جديد', 'phone' => '01000987654', 'password' => 'password',
            'company_name' => 'شركة', 'tax_number' => '123', 'commercial_register' => '456',
            'documents' => [UploadedFile::fake()->create('doc.jpg', 20, 'application/pdf')],
        ])->assertCreated();

        // الإدارة استلمت إشعار التسجيل
        $this->assertSame(1, $this->admin->fresh()->notifications()->where('type', WholesaleApplicationSubmittedNotification::class)->count());

        $trader = Customer::query()->where('phone', '01000987654')->firstOrFail();
        $this->asAdmin()->postJson("/api/v1/admin/customers/{$trader->id}/reject", ['reason' => 'مستندات ناقصة'])
            ->assertOk();

        $notification = $trader->fresh()->notifications()->first();
        $this->assertSame('wholesale_application_reviewed', $notification->data['kind']);
        $this->assertSame('rejected', $notification->data['decision']);
        $this->assertSame('مستندات ناقصة', $notification->data['reason']);
    }

    public function test_purchase_order_sent_notifies_supplier_users(): void
    {
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $supplierUser = SupplierUser::factory()->create(['supplier_id' => $supplier->id, 'is_active' => true]);
        $po = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'approved_by' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);
        PurchaseOrderItem::factory()->create(['purchase_order_id' => $po->id]);

        app(ProcurementService::class)->sendPurchaseOrder($po);

        $this->assertSame(1, $supplierUser->fresh()->notifications()->count());
        $this->assertSame('purchase_order_sent', $supplierUser->fresh()->notifications()->first()->data['kind']);

        // بوابة المورد ترى الإشعار عبر المتحكم الموحد
        $this->asUser($supplierUser, 'supplier')->getJson('/api/v1/supplier/notifications')
            ->assertOk()->assertJsonPath('meta.unread_count', 1);
    }
}
