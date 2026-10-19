<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\ReturnRequest;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierDelivery;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\PrivateAttachments;
use App\Services\ProcurementService;
use App\Services\ReturnService;
use App\Services\SupplierFinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PhaseNineTenHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    private Supplier $supplier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->branch = Branch::factory()->create();
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create(['cost_price' => 100, 'is_active' => true]);
    }

    private function api(string $uri, array $data = [], ?string $key = null): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->admin->createToken('review')->plainTextToken)
            ->postJson('/api/v1/admin/'.$uri, $data, $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    private function po(PurchaseOrderStatus $status = PurchaseOrderStatus::SentToSupplier): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create([
            'supplier_id' => $this->supplier->id, 'branch_id' => $this->branch->id,
            'status' => $status, 'subtotal' => 13000, 'discount_amount' => 0,
            'approved_by' => null, 'created_by' => $this->admin->id,
        ]);
        $po->items()->create(['product_variant_id' => $this->variant->id, 'ordered_qty' => 100, 'received_qty' => 0, 'unit_cost' => 130, 'tax_rate' => 0, 'line_total' => 13000]);

        return $po->fresh(['items']);
    }

    private function order(int $qty = 3, float $price = 100, float $discount = 0): Order
    {
        // طلب مُسلّم مدفوع (COD تُسوّى عند التسليم في التدفق الحقيقي) حتى
        // تكون الردود مشروعة أمام حارس H1 الذي يرفض رد غير المدفوع
        $order = Order::factory()->create(['customer_id' => Customer::factory()->create()->id, 'status' => OrderStatus::Delivered, 'payment_status' => 'paid']);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_variant_id' => $this->variant->id, 'qty' => $qty, 'unit_price' => $price, 'discount_amount' => $discount]);
        $order->statusHistories()->create(['to_status' => OrderStatus::Delivered, 'created_at' => now()]);

        return $order->fresh(['items']);
    }

    private function pending(Order $order, int $qty = 1): ReturnRequest
    {
        return app(ReturnService::class)->createReturnRequest($order, ['reason' => 'عيب', 'items' => [['order_item_id' => $order->items->first()->id, 'requested_qty' => $qty]]]);
    }

    private function receiveReturn(ReturnRequest $return): ReturnRequest
    {
        $service = app(ReturnService::class);
        $service->approve($return, $this->admin, ['branch_id' => $this->branch->id]);

        return $service->receive($return, $this->admin, ['items' => [['id' => $return->items->first()->id, 'received_good_qty' => $return->items->first()->requested_qty, 'received_damaged_qty' => 0]]]);
    }

    public function test_receipt_retry_changes_stock_and_cost_once_and_conflicting_payload_returns_409(): void
    {
        $po = $this->po();
        $payload = ['items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 40]]];
        $this->api("purchase-orders/{$po->id}/receive", $payload, 'receipt-1')->assertOk();
        $cost = $this->variant->fresh()->cost_price;
        // Retry under the same actor/token identity.
        $this->api("purchase-orders/{$po->id}/receive", $payload, 'receipt-1')->assertOk();
        $payload['items'][0]['qty'] = 30;
        $this->api("purchase-orders/{$po->id}/receive", $payload, 'receipt-1')->assertConflict();
        $this->assertSame(40.0, (float) $po->items->first()->fresh()->received_qty);
        $this->assertSame(40.0, (float) StockLevel::sum('on_hand'));
        $this->assertSame($cost, $this->variant->fresh()->cost_price);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_receipt_without_key_returns_422_and_leaves_stock_unchanged(): void
    {
        $po = $this->po();
        $this->api("purchase-orders/{$po->id}/receive", ['items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 40]]])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_payment_retry_and_duplicate_bank_reference_do_not_double_debit_supplier(): void
    {
        $data = ['supplier_id' => $this->supplier->id, 'amount' => 40, 'payment_method' => 'bank_transfer', 'reference_no' => 'BANK-40', 'payment_date' => '2026-10-19'];
        $id = $this->api('supplier-payments', $data, 'pay-1')->assertCreated()->json('data.id');
        $this->api('supplier-payments', $data, 'pay-1')->assertCreated()->assertJsonPath('data.id', $id);
        $data['reference_no'] = ' bank-40 ';
        $this->api('supplier-payments', $data, 'pay-2')->assertConflict();
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertSame('-40.00', $this->supplier->fresh()->current_balance);
    }

    public function test_delivery_retry_creates_one_asn_and_one_private_invoice(): void
    {
        Storage::fake('local');
        $po = $this->po();
        $user = SupplierUser::factory()->create(['supplier_id' => $this->supplier->id]);
        $data = ['delivery_date' => '2026-10-19', 'items' => [['purchase_order_item_id' => $po->items->first()->id, 'shipped_qty' => 20]]];
        $service = app(ProcurementService::class);
        $file = UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf');
        $first = $service->createSupplierDelivery($po, $user, $data, $file, 'asn-1');
        $second = $service->createSupplierDelivery($po, $user, $data, $file, 'asn-1');
        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('supplier_deliveries', 1);
    }

    public function test_sending_requires_approval_and_edit_invalidates_previous_approval(): void
    {
        $po = $this->po(PurchaseOrderStatus::Draft);
        // المنشئ شخص آخر حتى لا يصطدم الاعتماد بحظر الاعتماد الذاتي (M5)
        $po->update(['created_by' => User::factory()->create()->id]);
        $this->api("purchase-orders/{$po->id}/send")->assertUnprocessable();
        $this->api("purchase-orders/{$po->id}/approve")->assertOk();
        Auth::forgetGuards();
        $this->withToken($this->admin->createToken('review')->plainTextToken)->patchJson("/api/v1/admin/purchase-orders/{$po->id}", ['discount_amount' => 10])->assertOk();
        $this->assertNull($po->fresh()->approved_by);
        $this->api("purchase-orders/{$po->id}/send")->assertUnprocessable();
        $this->api("purchase-orders/{$po->id}/approve")->assertOk();
        $this->api("purchase-orders/{$po->id}/send")->assertOk();
    }

    #[TestWith(['supplier-payments', 'purchases.create'])]
    #[TestWith(['purchase-orders/1/approve', 'purchases.update'])]
    #[TestWith(['purchase-orders/1/receive', 'purchases.update'])]
    #[TestWith(['supplier-invoices/1/post', 'purchases.update'])]
    #[TestWith(['returns/1/receive', 'returns.update'])]
    #[TestWith(['returns/1/refund', 'returns.update'])]
    public function test_general_module_permission_does_not_grant_financial_action(string $uri, string $permission): void
    {
        if (str_starts_with($uri, 'purchase-orders/')) {
            $uri = str_replace('/1/', '/'.$this->po(PurchaseOrderStatus::Draft)->id.'/', $uri);
        } elseif (str_starts_with($uri, 'returns/')) {
            $uri = str_replace('/1/', '/'.$this->pending($this->order())->id.'/', $uri);
        } elseif (str_starts_with($uri, 'supplier-invoices/')) {
            $invoice = SupplierInvoice::factory()->create(['supplier_id' => $this->supplier->id]);
            $uri = str_replace('/1/', '/'.$invoice->id.'/', $uri);
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo($permission);
        Auth::forgetGuards();
        $this->withToken($staff->createToken('review')->plainTextToken)->postJson('/api/v1/admin/'.$uri)->assertForbidden();
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_stale_pending_return_cannot_be_deleted_after_receipt(): void
    {
        $pending = $this->pending($this->order());
        $stale = clone $pending;
        $this->receiveReturn($pending);
        try {
            app(ReturnService::class)->delete($stale);
            $this->fail('Received RMA must survive stale deletes.');
        } catch (BusinessException $exception) {
            $this->assertSame(422, $exception->status);
        }
        $this->assertModelExists($pending);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_refunded_return_cannot_change_method_using_a_stale_model(): void
    {
        $return = $this->receiveReturn($this->pending($this->order()));
        $stale = clone $return;
        app(ReturnService::class)->refund($return, $this->admin, ['refund_method' => 'wallet']);
        try {
            app(ReturnService::class)->updateDetails($stale, $this->admin, ['refund_method' => 'cash']);
            $this->fail('A posted refund method cannot be rewritten.');
        } catch (BusinessException $exception) {
            $this->assertSame(422, $exception->status);
        }
        $this->assertDatabaseHas('return_requests', ['id' => $return->id, 'refund_method' => 'wallet']);
        $this->assertDatabaseHas('customer_wallet_transactions', ['reference_id' => $return->id, 'amount' => 100]);
    }

    public function test_partial_refunds_never_exceed_the_original_line_net_amount(): void
    {
        $order = $this->order(3, 1, 2);
        for ($i = 0; $i < 3; $i++) {
            $return = $this->receiveReturn($this->pending($order));
            app(ReturnService::class)->refund($return, $this->admin, ['refund_method' => 'wallet']);
        }
        $this->assertSame('1.00', $order->customer->fresh()->wallet_balance);
        $this->assertSame(1.0, (float) CustomerWalletTransaction::sum('amount'));
        $this->assertSame([0.33, 0.34, 0.33], CustomerWalletTransaction::orderBy('id')->get()->map(fn ($row) => (float) $row->amount)->all());
    }

    public function test_customer_only_lists_and_downloads_their_own_return_attachments(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $order = $this->order();
        $return = app(ReturnService::class)->createReturnRequest($order, ['reason' => 'عيب', 'items' => [['order_item_id' => $order->items->first()->id, 'requested_qty' => 1, 'attachments' => [UploadedFile::fake()->create('defect.png', 1, 'image/png')]]]]);
        $path = $return->items->first()->attachments[0];
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        Auth::forgetGuards();
        $token = $order->customer->createToken('review')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/store/account/returns')->assertOk()->assertJsonCount(1, 'data');
        $url = "/api/v1/store/account/return-items/{$return->items->first()->id}/attachments/0";
        $this->withToken($token)->get($url)->assertOk();
        Auth::forgetGuards();
        $other = Customer::factory()->create();
        $this->withToken($other->createToken('review')->plainTextToken)->getJson("/api/v1/store/account/returns/{$return->id}")->assertNotFound();
        $this->get($url)->assertNotFound();
    }

    public function test_file_and_database_changes_roll_back_together_on_failure(): void
    {
        Storage::fake('local');
        try {
            PrivateAttachments::transaction(function (PrivateAttachments $files): void {
                $files->store(UploadedFile::fake()->create('test.pdf'), 'review');
                Supplier::factory()->create(['code' => 'ROLLBACK-FILE']);
                throw new \RuntimeException('stop');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('stop', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseMissing('suppliers', ['code' => 'ROLLBACK-FILE']);
    }

    public function test_draft_orders_and_internal_payment_notes_are_hidden_from_supplier(): void
    {
        $draft = $this->po(PurchaseOrderStatus::Draft);
        SupplierPayment::factory()->create(['supplier_id' => $this->supplier->id, 'notes' => 'internal-only']);
        $user = SupplierUser::factory()->create(['supplier_id' => $this->supplier->id]);
        Auth::forgetGuards();
        $this->withToken($user->createToken('review')->plainTextToken)->getJson('/api/v1/supplier/purchase-orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/supplier/purchase-orders/{$draft->id}")->assertNotFound();
        $this->getJson('/api/v1/supplier/dashboard')->assertOk()->assertJsonMissing(['notes' => 'internal-only']);
    }

    public function test_duplicate_invoice_number_is_rejected_case_insensitively(): void
    {
        $data = ['supplier_id' => $this->supplier->id, 'supplier_invoice_no' => 'ABC-1', 'issue_date' => '2026-10-19', 'subtotal' => 100];
        $this->api('supplier-invoices', $data)->assertCreated();
        $data['supplier_invoice_no'] = ' abc-1 ';
        $this->api('supplier-invoices', $data)->assertConflict();
        $this->assertDatabaseCount('supplier_invoices', 1);
    }

    public function test_cancelled_or_posted_invoice_cannot_be_deleted_through_stale_draft(): void
    {
        $invoice = app(SupplierFinanceService::class)->createInvoice(['supplier_id' => $this->supplier->id, 'supplier_invoice_no' => 'DELETE-1', 'issue_date' => '2026-10-19', 'subtotal' => 100]);
        $stale = clone $invoice;
        app(SupplierFinanceService::class)->postInvoice($invoice);
        try {
            app(SupplierFinanceService::class)->deleteInvoice($stale);
            $this->fail('Posted invoice must not be deleted.');
        } catch (BusinessException $exception) {
            $this->assertSame(422, $exception->status);
        }
        $this->assertModelExists($invoice);
        $this->assertSame('100.00', $this->supplier->fresh()->current_balance);
    }

    public function test_cost_includes_header_discount_and_all_branches_physical_stock(): void
    {
        $po = $this->po();
        $po->update(['discount_amount' => 2600]);
        $otherBranch = Branch::factory()->create();
        StockLevel::create(['branch_id' => $otherBranch->id, 'product_variant_id' => $this->variant->id, 'on_hand' => 100, 'damaged' => 20]);
        $this->api("purchase-orders/{$po->id}/receive", ['items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 100]]], 'discount-cost')->assertOk();
        $this->assertSame('102.0000', $this->variant->fresh()->cost_price);
        $this->assertSame('104.0000', $this->variant->fresh()->last_cost_price);
        $this->assertDatabaseHas('stock_movements', ['unit_cost' => 104, 'quantity' => 100]);
    }

    public function test_receipt_rejects_foreign_branch_location_without_cost_or_stock_changes(): void
    {
        $po = $this->po();
        $location = Location::factory()->create();
        $this->api("purchase-orders/{$po->id}/receive", ['location_id' => $location->id, 'items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 10]]], 'foreign-location')->assertUnprocessable();
        $this->assertSame('100.0000', $this->variant->fresh()->cost_price);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_pending_delivery_requires_matching_receipt_and_short_close_rejects_remaining_asn(): void
    {
        $po = $this->po();
        $user = SupplierUser::factory()->create(['supplier_id' => $this->supplier->id]);
        $delivery = app(ProcurementService::class)->createSupplierDelivery($po, $user, ['delivery_date' => '2026-10-19', 'items' => [['purchase_order_item_id' => $po->items->first()->id, 'shipped_qty' => 40]]], null, 'asn-1');
        $payload = ['items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 20]]];
        $this->api("purchase-orders/{$po->id}/receive", $payload, 'direct')->assertUnprocessable();
        $this->api("purchase-orders/{$po->id}/receive", [...$payload, 'supplier_delivery_id' => $delivery->id], 'matched')->assertOk();
        $this->api("purchase-orders/{$po->id}/close-short")->assertOk();
        $this->assertSame(PurchaseOrderStatus::Completed, $po->fresh()->status);
        $this->assertDatabaseHas('supplier_deliveries', ['id' => $delivery->id, 'status' => 'rejected']);
        $this->assertSame(20.0, (float) $po->items->first()->fresh()->received_qty);
    }

    public function test_supplier_statement_rejects_period_longer_than_366_days(): void
    {
        $user = SupplierUser::factory()->create(['supplier_id' => $this->supplier->id]);
        Auth::forgetGuards();
        $this->withToken($user->createToken('review')->plainTextToken)->getJson('/api/v1/supplier/statement?from_date=2020-01-01&to_date=2026-10-19')->assertUnprocessable();
    }

    public function test_supplier_password_reset_revokes_existing_tokens(): void
    {
        $user = SupplierUser::factory()->create(['supplier_id' => $this->supplier->id]);
        $token = $user->createToken('review')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($this->admin->createToken('review')->plainTextToken)->putJson("/api/v1/admin/suppliers/{$this->supplier->id}/users/{$user->id}", ['password' => 'new-password-123'])->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/supplier/dashboard')->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    #[TestWith(['opening_balance'])]
    #[TestWith(['is_active'])]
    #[TestWith(['payment_terms_days'])]
    public function test_null_nonnullable_supplier_fields_return_422(string $field): void
    {
        $this->api('suppliers', ['code' => 'NULL-FIELD', 'name' => 'Review supplier', $field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseMissing('suppliers', ['code' => 'NULL-FIELD']);
    }

    public function test_fractional_return_and_large_item_array_return_422(): void
    {
        $order = $this->order();
        $payload = ['order_id' => $order->id, 'reason' => 'عيب', 'items' => [['order_item_id' => $order->items->first()->id, 'requested_qty' => 0.5]]];
        $this->api('returns', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.requested_qty');
        $payload['items'] = array_fill(0, 51, ['order_item_id' => $order->items->first()->id, 'requested_qty' => 1]);
        $this->api('returns', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('return_requests', 0);
    }

    public function test_missing_delivery_history_does_not_extend_return_window(): void
    {
        $order = $this->order();
        $order->statusHistories()->delete();
        $this->api('returns', ['order_id' => $order->id, 'reason' => 'عيب', 'items' => [['order_item_id' => $order->items->first()->id, 'requested_qty' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('return_requests', 0);
    }

    public function test_invoice_due_date_can_be_updated_alone_or_recomputed_from_terms(): void
    {
        $id = $this->api('supplier-invoices', ['supplier_id' => $this->supplier->id, 'supplier_invoice_no' => 'DUE-1', 'issue_date' => '2026-10-01', 'subtotal' => 100])->assertCreated()->json('data.id');
        Auth::forgetGuards();
        $this->withToken($this->admin->createToken('review')->plainTextToken)->patchJson("/api/v1/admin/supplier-invoices/{$id}", ['due_date' => '2026-12-01'])->assertOk()->assertJsonPath('data.due_date', '2026-12-01');
        $this->patchJson("/api/v1/admin/supplier-invoices/{$id}", ['due_date' => null])->assertOk()->assertJsonPath('data.due_date', '2026-10-31');
        $this->patchJson("/api/v1/admin/supplier-invoices/{$id}", ['tax_amount' => null])->assertUnprocessable();
    }

    public function test_excessive_discount_and_payment_amount_return_422(): void
    {
        $this->api('purchase-orders', ['supplier_id' => $this->supplier->id, 'branch_id' => $this->branch->id, 'discount_amount' => 200,
            'items' => [['product_variant_id' => $this->variant->id, 'ordered_qty' => 1, 'unit_cost' => 100]]])->assertUnprocessable();
        $this->api('supplier-payments', ['supplier_id' => $this->supplier->id, 'amount' => 10000000000, 'payment_method' => 'cash', 'payment_date' => '2026-10-19'], 'huge-payment')->assertUnprocessable();
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_weighted_average_retains_four_decimal_places(): void
    {
        $po = $this->po();
        $po->update(['discount_amount' => 1690]);
        StockLevel::create(['branch_id' => $this->branch->id, 'product_variant_id' => $this->variant->id, 'on_hand' => 2]);
        $this->api("purchase-orders/{$po->id}/receive", ['items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 1]]], 'precise-cost')->assertOk();
        $this->assertSame('104.3667', $this->variant->fresh()->cost_price);
    }

    public function test_wallet_ledger_is_immutable_and_balance_cannot_become_negative(): void
    {
        $return = $this->receiveReturn($this->pending($this->order()));
        app(ReturnService::class)->refund($return, $this->admin, ['refund_method' => 'wallet']);
        $entry = CustomerWalletTransaction::firstOrFail();
        foreach (['update', 'delete', 'negative_balance', 'duplicate_reference'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $entry, $return): void {
                    if ($operation === 'update') {
                        CustomerWalletTransaction::whereKey($entry->id)->update(['amount' => 999]);
                    } elseif ($operation === 'delete') {
                        CustomerWalletTransaction::whereKey($entry->id)->delete();
                    } elseif ($operation === 'negative_balance') {
                        Customer::whereKey($return->customer_id)->update(['wallet_balance' => -1]);
                    } else {
                        CustomerWalletTransaction::create(collect($entry->getAttributes())->except('id')->all());
                    }
                });
                $this->fail('Wallet ledger safeguards must reject '.$operation);
            } catch (QueryException $exception) {
                $this->assertContains($exception->errorInfo[0], ['P0001', '23514', '23505']);
            }
        }
        $this->assertSame('100.00', $entry->fresh()->amount);
        $this->assertSame('100.00', Customer::findOrFail($return->customer_id)->wallet_balance);
        $this->assertDatabaseCount('customer_wallet_transactions', 1);
    }

    public function test_payment_edit_cannot_remove_bank_reference_or_reuse_another_payment_reference(): void
    {
        $data = ['supplier_id' => $this->supplier->id, 'amount' => 20, 'payment_method' => 'bank_transfer', 'payment_date' => '2026-10-19'];
        $first = $this->api('supplier-payments', [...$data, 'reference_no' => 'BANK-A'], 'edit-pay-1')->assertCreated()->json('data.id');
        $this->api('supplier-payments', [...$data, 'reference_no' => 'BANK-B'], 'edit-pay-2')->assertCreated();
        Auth::forgetGuards();
        $this->withToken($this->admin->createToken('review')->plainTextToken)->patchJson("/api/v1/admin/supplier-payments/{$first}", ['reference_no' => null])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/supplier-payments/{$first}", ['reference_no' => 'bank-b'])->assertConflict();
        $this->assertDatabaseHas('supplier_payments', ['id' => $first, 'reference_no' => 'BANK-A']);
        $this->assertSame('-40.00', $this->supplier->fresh()->current_balance);
    }

    public function test_cancelling_purchase_order_releases_internal_request_and_rejects_pending_delivery(): void
    {
        $request = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id, 'requested_by' => $this->admin->id, 'status' => 'converted_to_po']);
        $po = $this->po();
        $po->update(['purchase_request_id' => $request->id]);
        $delivery = SupplierDelivery::factory()->create(['supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id, 'status' => 'pending_receipt']);
        $this->api("purchase-orders/{$po->id}/cancel")->assertOk();
        $this->assertDatabaseHas('purchase_requests', ['id' => $request->id, 'status' => 'approved']);
        $this->assertDatabaseHas('supplier_deliveries', ['id' => $delivery->id, 'status' => 'rejected']);
        Auth::forgetGuards();
        $this->withToken($this->admin->createToken('review')->plainTextToken)->deleteJson("/api/v1/admin/purchase-orders/{$po->id}")->assertUnprocessable();
        $this->assertModelExists($po);
    }

    public function test_inactive_supplier_and_branch_cannot_be_used_for_new_documents(): void
    {
        $this->supplier->update(['is_active' => false]);
        $this->api('supplier-invoices', ['supplier_id' => $this->supplier->id, 'supplier_invoice_no' => 'INACTIVE', 'issue_date' => '2026-10-19', 'subtotal' => 100])->assertUnprocessable();
        $this->api('purchase-orders', ['supplier_id' => $this->supplier->id, 'branch_id' => $this->branch->id, 'items' => [['product_variant_id' => $this->variant->id, 'ordered_qty' => 1, 'unit_cost' => 100]]])->assertUnprocessable();
        $return = $this->pending($this->order());
        $this->branch->update(['is_active' => false]);
        $this->api("returns/{$return->id}/approve", ['branch_id' => $this->branch->id])->assertUnprocessable();
        $this->assertDatabaseHas('return_requests', ['id' => $return->id, 'status' => 'pending']);
    }

    public function test_supplier_invoice_and_delivery_downloads_are_scoped_to_supplier(): void
    {
        Storage::fake('local');
        $invoice = app(SupplierFinanceService::class)->createInvoice(['supplier_id' => $this->supplier->id, 'supplier_invoice_no' => 'ATTACH-1', 'issue_date' => '2026-10-19', 'subtotal' => 100], UploadedFile::fake()->create('invoice.pdf', 5, 'application/pdf'));
        app(SupplierFinanceService::class)->postInvoice($invoice);
        $po = $this->po();
        $delivery = SupplierDelivery::factory()->create(['supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id, 'invoice_file_path' => $invoice->attachment_path]);
        $owner = SupplierUser::factory()->create(['supplier_id' => $this->supplier->id]);
        Auth::forgetGuards();
        $this->withToken($owner->createToken('review')->plainTextToken)->get("/api/v1/supplier/supplier-invoices/{$invoice->id}/attachment")->assertOk();
        $this->get("/api/v1/supplier/deliveries/{$delivery->id}/invoice")->assertOk();
        Auth::forgetGuards();
        $other = SupplierUser::factory()->create(['supplier_id' => Supplier::factory()->create()->id]);
        $this->withToken($other->createToken('review')->plainTextToken)->get("/api/v1/supplier/supplier-invoices/{$invoice->id}/attachment")->assertNotFound();
        $this->get("/api/v1/supplier/deliveries/{$delivery->id}/invoice")->assertNotFound();
    }

    public function test_fulfilled_refund_cannot_be_replayed_even_after_receipt_status_is_tampered_with(): void
    {
        $return = $this->receiveReturn($this->pending($this->order()));
        app(ReturnService::class)->refund($return, $this->admin, ['refund_method' => 'wallet']);
        ReturnRequest::query()->whereKey($return->id)->update(['status' => 'received']);
        $this->api("returns/{$return->id}/refund", ['refund_method' => 'wallet'])->assertConflict();
        $this->assertSame('100.00', $return->customer->fresh()->wallet_balance);
        $this->assertDatabaseCount('customer_wallet_transactions', 1);
    }
}
