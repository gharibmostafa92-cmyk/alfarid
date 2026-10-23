<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ManufacturingBusinessTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_receiving_own_production_adds_stock_and_updates_weighted_cost_without_supplier_debt(): void
    {
        $staff = $this->staff();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create(['cost_price' => 10]);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
            'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 10, 'created_by' => $staff->id,
        ]);
        $data = $this->payload($branch, $variant, 10, '30');
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'batch-1'])
            ->assertCreated()->assertJsonPath('data.batch_number', $data['batch_number']);
        $this->assertDatabaseHas('stock_levels', ['product_variant_id' => $variant->id, 'on_hand' => 20]);
        $this->assertSame('20.0000', $variant->fresh()->cost_price);
        $this->assertSame('30.0000', $variant->fresh()->last_cost_price);
        $this->assertDatabaseHas('stock_movements', ['type' => 'production_receipt', 'quantity' => 10, 'unit_cost' => 30]);
        $this->assertDatabaseCount('supplier_invoices', 0);
    }

    public function test_retries_do_not_duplicate_stock_and_changed_payload_or_duplicate_batch_is_rejected(): void
    {
        $staff = $this->staff();
        $data = $this->payload(Branch::factory()->create(), ProductVariant::factory()->create());
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'same'])->assertCreated();
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'same'])->assertCreated();
        $changed = $data;
        $changed['items'][0]['quantity'] = 99;
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $changed, ['Idempotency-Key' => 'same'])->assertConflict();
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'different'])->assertConflict();
        $this->assertDatabaseCount('production_receipts', 1);
        $this->assertSame(1, StockMovement::where('type', 'production_receipt')->count());
        $this->assertDatabaseHas('stock_levels', ['on_hand' => 5]);
    }

    public function test_receipt_requires_login_cost_permission_and_branch_access(): void
    {
        $branch = Branch::factory()->create();
        $data = $this->payload($branch, ProductVariant::factory()->create());
        $this->postJson('/api/v1/admin/production-receipts', $data)->assertUnauthorized();
        $limited = $this->staff(['inventory.adjust', 'inventory.view']);
        $this->login($limited)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'limited'])->assertForbidden();
        $other = $this->staff(['inventory.adjust', 'products.view_cost']);
        $other->update(['branch_id' => Branch::factory()->create()->id]);
        $this->login($other)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'other'])->assertForbidden();
        $this->assertDatabaseCount('production_receipts', 0);
    }

    public function test_invalid_quantities_duplicate_lines_and_foreign_locations_do_not_change_stock(): void
    {
        $staff = $this->staff();
        $branch = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        $data = $this->payload($branch, $variant);
        $data['items'][0]['quantity'] = '1.5';
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'fraction'])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $data = $this->payload($branch, $variant);
        $data['items'][] = $data['items'][0];
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'duplicate'])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_variant_id');
        $data = $this->payload($branch, $variant);
        $data['location_id'] = Location::factory()->create()->id;
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'location'])->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $this->assertDatabaseCount('production_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_failed_receipt_rolls_back_all_lines_and_costs(): void
    {
        $staff = $this->staff();
        $branch = Branch::factory()->create();
        $first = ProductVariant::factory()->create(['cost_price' => 10]);
        $second = ProductVariant::factory()->create();
        $second->product->delete();
        $data = $this->payload($branch, $first);
        $data['items'][] = ['product_variant_id' => $second->id, 'quantity' => 3, 'unit_cost' => 50];
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'rollback'])->assertUnprocessable();
        $this->assertDatabaseCount('production_receipts', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('10.0000', $first->fresh()->cost_price);
    }

    public function test_history_is_branch_scoped_and_hides_cost_without_permission(): void
    {
        $branch = Branch::factory()->create();
        $staff = $this->staff();
        $data = $this->payload($branch, ProductVariant::factory()->create());
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $data, ['Idempotency-Key' => 'history'])->assertCreated();
        $viewer = $this->staff(['inventory.view']);
        $viewer->update(['branch_id' => $branch->id]);
        $this->login($viewer)->getJson('/api/v1/admin/production-receipts')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.items.0.unit_cost');
        $viewer->update(['branch_id' => Branch::factory()->create()->id]);
        $this->login($viewer)->getJson('/api/v1/admin/production-receipts')->assertOk()->assertJsonCount(0, 'data');
        $this->login($viewer)->getJson('/api/v1/admin/production-receipts/options')->assertOk()->assertJsonCount(1, 'data.branches');
    }

    public function test_resale_endpoints_are_retired(): void
    {
        foreach (['admin/brands', 'store/brands', 'admin/suppliers', 'admin/purchase-orders', 'admin/purchase-requests', 'admin/supplier-invoices', 'admin/supplier-payments', 'supplier/purchase-orders', 'admin/reports/suppliers'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertStatus(410);
        }
    }

    public function test_own_products_reject_brand_and_supplier_fields(): void
    {
        $staff = $this->staff(['products.create']);
        $product = Product::factory()->create();
        $this->login($staff)->postJson('/api/v1/admin/products', [
            'name_ar' => 'حقيبة الفريد', 'name_en' => 'Alfarid Bag', 'category_id' => $product->category_id,
            'sales_channel' => 'both', 'brand_id' => 1, 'supplier_id' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['brand_id', 'supplier_id']);
        $this->login($staff)->postJson('/api/v1/admin/products', [
            'name_ar' => 'حقيبة الفريد', 'name_en' => 'Alfarid Bag', 'category_id' => $product->category_id,
            'sales_channel' => 'both',
        ])->assertCreated()->assertJsonPath('data.brand', null)->assertJsonPath('data.supplier_id', null);
        $this->assertDatabaseHas('products', ['name_en' => 'Alfarid Bag', 'brand_id' => null, 'supplier_id' => null]);
    }

    public function test_old_inactive_brand_does_not_hide_our_published_products(): void
    {
        $brand = Brand::factory()->create(['is_active' => false]);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'status' => 'published', 'sales_channel' => 'both']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100]);
        $this->getJson('/api/v1/store/products')->assertOk()->assertJsonFragment(['id' => $product->id, 'name_ar' => $product->name_ar]);
    }

    public function test_dealer_list_contains_wholesale_and_distributors_without_retail_customers(): void
    {
        $staff = $this->staff(['customers.view']);
        Customer::factory()->create(['type' => 'retail']);
        Customer::factory()->create(['type' => 'wholesale']);
        Customer::factory()->create(['type' => 'distributor']);
        $this->login($staff)->getJson('/api/v1/admin/customers?business_only=1')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_upload_and_review_a_dealer_document_before_approval(): void
    {
        $staff = $this->staff(['customers.update', 'customers.documents.view', 'customers.approve']);
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'pending']);
        $this->login($staff)->postJson('/api/v1/admin/customers/'.$customer->id.'/documents', [
            'document' => UploadedFile::fake()->image('tax-card.png'),
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $document = $customer->documents()->firstOrFail();
        Storage::disk('r2')->assertExists($document->file_path);
        $this->login($staff)->patchJson('/api/v1/admin/customers/'.$customer->id.'/documents/'.$document->id, ['status' => 'verified'])->assertOk();
        $this->assertSame('verified', $document->fresh()->status->value);
    }

    public function test_document_upload_requires_permissions_and_rejects_non_documents(): void
    {
        $customer = Customer::factory()->create(['type' => 'wholesale']);
        $limited = $this->staff(['customers.view']);
        $this->login($limited)->postJson('/api/v1/admin/customers/'.$customer->id.'/documents', [
            'document' => UploadedFile::fake()->image('tax-card.png'),
        ])->assertForbidden();
        $staff = $this->staff(['customers.update', 'customers.documents.view']);
        $this->login($staff)->postJson('/api/v1/admin/customers/'.$customer->id.'/documents', [
            'document' => UploadedFile::fake()->create('script.html', 1, 'text/html'),
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertDatabaseCount('customer_documents', 0);
    }

    public function test_receipt_requires_a_retry_key(): void
    {
        $staff = $this->staff();
        $data = $this->payload(Branch::factory()->create(), ProductVariant::factory()->create());
        $this->login($staff)->postJson('/api/v1/admin/production-receipts', $data)->assertUnprocessable();
        $this->assertDatabaseCount('production_receipts', 0);
    }

    private function staff(array $permissions = ['inventory.view', 'inventory.adjust', 'inventory.all_branches', 'products.view_cost']): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function login(User $user): static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function payload(Branch $branch, ProductVariant $variant, int $quantity = 5, string $cost = '20'): array
    {
        return ['batch_number' => 'FACTORY-001', 'branch_id' => $branch->id, 'items' => [
            ['product_variant_id' => $variant->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]];
    }
}
