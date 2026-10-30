<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\SupplierDelivery;
use App\Models\SupplierInvoice;
use App\Models\SupplierUser;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ExercisesHistoricalResale;
use Tests\TestCase;

class TaskAttachmentChecklistTest extends TestCase
{
    use ExercisesHistoricalResale;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exerciseHistoricalResale();
    }

    #[DataProvider('unsafeUploads')]
    public function test_private_upload_endpoints_reject_unsafe_files_before_storage(string $endpoint, string $extension, string $mime, int $size): void
    {
        $this->seed([RolePermissionSeeder::class, WholesalePortalPermissionSeeder::class]);
        $file = UploadedFile::fake()->create('proof.'.$extension, $size, $mime);
        $actor = User::factory()->create();
        $actor->assignRole('Super Admin');
        $field = 'attachment';
        $payload = ['attachment' => $file];
        $url = '/api/v1/admin/supplier-invoices';
        if ($endpoint === 'delivery') {
            $actor = SupplierUser::factory()->create();
            $po = PurchaseOrder::factory()->create(['supplier_id' => $actor->supplier_id]);
            $url = '/api/v1/supplier/purchase-orders/'.$po->id.'/deliveries';
            $field = 'invoice_file';
            $payload = [$field => $file];
        } elseif ($endpoint === 'return') {
            $actor = Customer::factory()->create();
            $url = '/api/v1/store/account/returns';
            $field = 'items.0.attachments.0';
            $payload = ['items' => [['attachments' => [$file]]]];
        } elseif ($endpoint === 'notice') {
            $actor = Customer::factory()->wholesale()->create(['status' => 'approved']);
            $url = '/api/v1/wholesale/payment-notices';
            $field = 'receipt';
            $payload = [$field => $file];
        } elseif ($endpoint === 'document') {
            $customer = Customer::factory()->wholesale()->create();
            $url = '/api/v1/admin/customers/'.$customer->id.'/documents';
            $field = 'document';
            $payload = [$field => $file];
        }
        $this->withToken($actor->createToken('checklist')->plainTextToken)->withHeader('Accept', 'application/json')->post($url, $payload, ['Idempotency-Key' => 'unsafe-file'])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public static function unsafeUploads(): array
    {
        $cases = [];
        foreach (['invoice', 'delivery', 'return', 'notice', 'document'] as $endpoint) {
            foreach ([['html', 'text/html', 1], ['svg', 'image/svg+xml', 1], ['php', 'application/x-php', 1], ['pdf', 'application/pdf', 51200]] as [$ext, $mime, $size]) {
                $cases[$endpoint.' '.$ext] = [$endpoint, $ext, $mime, $size];
            }
        }

        return $cases;
    }

    #[DataProvider('staffAccess')]
    public function test_all_private_document_routes_enforce_permission_and_branch(string $kind, string $scope, bool $centralDocument, int $status): void
    {
        $this->seed([RolePermissionSeeder::class, WholesalePortalPermissionSeeder::class]);
        $own = Branch::factory()->create();
        $other = Branch::factory()->create();
        $actor = User::factory()->create(['branch_id' => $scope === 'unassigned' ? null : $own->id]);
        $permission = match ($kind) {
            'return' => 'returns.view', 'notice' => 'payment_notices.view', default => 'purchases.view',
        };
        if ($scope !== 'no_permission') {
            $actor->givePermissionTo($permission);
        }
        if ($scope === 'central') {
            $actor->givePermissionTo('inventory.all_branches');
        }
        $branchId = $centralDocument ? null : ($scope === 'other' ? $other->id : $own->id);
        $path = 'supplier_invoices/فاتورة.pdf';
        Storage::disk('r2')->put($path, 'private-document');
        if ($kind === 'return') {
            $order = Order::factory()->create(['allocated_branch_id' => $branchId]);
            $return = ReturnRequest::factory()->create(['order_id' => $order->id, 'customer_id' => $order->customer_id, 'branch_id' => $branchId]);
            $item = ReturnRequestItem::factory()->create(['return_request_id' => $return->id, 'attachments' => [$path]]);
            $url = 'return-items/'.$item->id.'/attachments/0';
        } elseif ($kind === 'notice') {
            $order = Order::factory()->create(['allocated_branch_id' => $branchId, 'customer_id' => Customer::factory()]);
            $notice = WholesalePaymentNotice::factory()->create(['order_id' => $order->id, 'customer_id' => $order->customer_id, 'receipt_file_path' => $path]);
            $url = 'payment-notices/'.$notice->id.'/receipt';
        } else {
            $po = $branchId === null ? null : PurchaseOrder::factory()->create(['branch_id' => $branchId]);
            if ($kind === 'invoice') {
                $invoice = SupplierInvoice::factory()->create(['purchase_order_id' => $po?->id, 'attachment_path' => $path, 'status' => 'posted']);
                $url = 'supplier-invoices/'.$invoice->id.'/attachment';
            } else {
                $delivery = SupplierDelivery::factory()->create(['purchase_order_id' => $po->id, 'invoice_file_path' => $path]);
                $url = 'supplier-deliveries/'.$delivery->id.'/invoice';
            }
        }
        $response = $this->withToken($actor->createToken('checklist')->plainTextToken)->getJson('/api/v1/admin/'.$url.'?signed=1')->assertStatus($status);
        if ($status === 200) {
            $response->assertHeader('Cache-Control', 'no-store, private')->assertJsonStructure(['url']);
        }
    }

    public static function staffAccess(): array
    {
        $cases = [];
        foreach (['return', 'invoice', 'delivery', 'notice'] as $kind) {
            foreach (['own' => 200, 'other' => 403, 'central' => 200, 'unassigned' => 403, 'no_permission' => 403] as $scope => $status) {
                $cases[$kind.' '.$scope] = [$kind, $scope, false, $status];
            }
            if ($kind !== 'delivery') {
                $cases[$kind.' central-document restricted'] = [$kind, 'own', true, 403];
                $cases[$kind.' central-document central'] = [$kind, 'central', true, 200];
            }
        }

        return $cases;
    }
}
