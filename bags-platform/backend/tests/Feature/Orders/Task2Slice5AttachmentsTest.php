<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\Orders\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\User;
use App\Services\Orders\ReturnService;
use Database\Seeders\ReturnPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Task2Slice5AttachmentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ReturnPermissionSeeder::class);
    }

    public function test_medium01_create_return_request_rejects_ineligible_order_before_uploading_to_r2(): void
    {
        Storage::fake('r2');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['status' => 'published']);
        $variant = $product->variants()->first() ?? $product->variants()->create([
            'name_ar' => 'قياسي',
            'sku' => 'SKU-'.uniqid(),
            'retail_price' => 100,
            'is_active' => true,
        ]);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
        ]);
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Bag',
            'sku_snapshot' => $variant->sku,
            'qty' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);

        try {
            app(ReturnService::class)->createReturnRequest($order, [
                'reason' => 'Not delivered yet',
                'items' => [[
                    'order_item_id' => $orderItem->id,
                    'requested_qty' => 1,
                    'attachments' => [UploadedFile::fake()->image('defect.jpg')],
                ]],
            ], $customer);
            $this->fail('Expected BusinessException was not thrown.');
        } catch (BusinessException) {
            $this->assertSame([], Storage::disk('r2')->allFiles());
        }
    }

    public function test_medium02_prune_private_attachments_prunes_up_to_max_deletions(): void
    {
        Storage::fake('r2');
        config(['filesystems.cleanup_owner' => 'test-owner']);
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert(
            ['key' => \App\Support\StorageCleanupOwner::SETTING_KEY],
            ['group' => 'system', 'value' => 'test-owner', 'type' => 'string']
        );
        $disk = Storage::disk('r2');
        $disk->put(\App\Support\StorageCleanupOwner::MARKER, \App\Support\StorageCleanupOwner::markerFor('test-owner'));
        $disk->put('return_requests/items/1/a.jpg', 'a');
        $disk->put('return_requests/items/1/b.jpg', 'b');
        $disk->put('return_requests/items/1/c.jpg', 'c');

        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $file) {
            touch($disk->path("return_requests/items/1/{$file}"), now()->subHours(30)->timestamp);
        }

        $this->artisan('attachments:prune-orphans --max-deletions=2')
            ->assertExitCode(0);

        $remaining = $disk->allFiles('return_requests');
        $this->assertCount(1, $remaining);
    }

    public function test_medium06_attachment_download_skips_network_exists_probe_by_default(): void
    {
        Storage::fake('r2');
        Storage::disk('r2')->buildTemporaryUrlsUsing(fn (string $path): string => 'https://r2.example.test/'.$path);

        $staff = User::factory()->create();
        $staff->assignRole('Super Admin');
        $token = $staff->createToken('admin')->plainTextToken;

        $order = Order::factory()->create();
        $return = ReturnRequest::query()->create([
            'return_number' => 'RET-M06-1',
            'order_id' => $order->id,
            'status' => 'pending',
            'total_refund_amount' => 0,
            'reason' => 'Test',
        ]);
        $item = ReturnRequestItem::query()->create([
            'return_request_id' => $return->id,
            'order_item_id' => OrderItem::query()->create([
                'order_id' => $order->id,
                'product_variant_id' => Product::factory()->create()->variants()->firstOrCreate(
                    ['sku' => 'SKU-M06'],
                    ['name_ar' => 'x', 'retail_price' => 10, 'is_active' => true]
                )->id,
                'product_name_snapshot' => 'Bag',
                'sku_snapshot' => 'SKU-M06',
                'qty' => 1,
                'unit_price' => 10,
                'line_total' => 10,
            ])->id,
            'product_variant_id' => Product::query()->first()->variants()->first()->id,
            'requested_qty' => 1,
            'unit_refund_price' => 10,
            'attachments' => ['return_requests/items/1/missing-on-local-probe.jpg'],
        ]);

        // Even without putting the file on fake disk, default download signs locally without 404 probe.
        $res = $this->withToken($token)->get("/api/v1/admin/return-items/{$item->id}/attachments/0");
        $res->assertRedirect('https://r2.example.test/return_requests/items/1/missing-on-local-probe.jpg');
    }
}
