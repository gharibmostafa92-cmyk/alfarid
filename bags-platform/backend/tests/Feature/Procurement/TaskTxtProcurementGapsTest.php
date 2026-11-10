<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Orders\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierUser;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\Orders\OrderService;
use App\Services\Procurement\SupplierFinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ExercisesHistoricalResale;
use Tests\TestCase;

/**
 * Remaining task.txt procurement, payment-webhook, and ledger cases that the
 * earlier suites do not lock down with these exact contracts.
 */
class TaskTxtProcurementGapsTest extends TestCase
{
    use ExercisesHistoricalResale;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_retired_procurement_routes_stay_410_while_sales_dashboard_and_payment_webhooks_do_not(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $token = $admin->createToken('gate')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/suppliers')->assertStatus(410);
        $this->withToken($token)->getJson('/api/v1/admin/reports/suppliers')->assertStatus(410);
        $this->getJson('/api/v1/store/brands')->assertStatus(410);
        $this->getJson('/api/v1/store/brands/any-slug')->assertStatus(404);
        $this->postJson('/api/v1/supplier/auth/login', ['email' => 'a@b.c', 'password' => 'password123'])->assertStatus(410);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/reports/sales')->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->postJson('/api/v1/webhooks/payment/missing-gateway', ['status' => 'paid'])->assertNotFound();

        $supplierUser = SupplierUser::factory()->create();
        $this->postJson('/api/v1/auth/login', [
            'login' => $supplierUser->email,
            'password' => 'password123',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->getJson('/api/v1/supplier/dashboard')->assertStatus(410);
    }

    public function test_supplier_balance_follows_draft_post_pay_void_and_cancelled_invoice(): void
    {
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create(['opening_balance' => 100, 'current_balance' => 100]);
        $service = app(SupplierFinanceService::class);

        $draft = $service->createInvoice([
            'supplier_id' => $supplier->id, 'supplier_invoice_no' => 'WALK-1',
            'issue_date' => '2026-03-01', 'subtotal' => 1000,
        ]);
        $this->assertSame('100.00', $supplier->fresh()->current_balance);

        $service->postInvoice($draft);
        $this->assertSame('1100.00', $supplier->fresh()->current_balance);
        $this->assertSame('posted', $draft->fresh()->status->value);

        $first = $service->createPayment([
            'supplier_id' => $supplier->id, 'supplier_invoice_id' => $draft->id,
            'amount' => 400, 'payment_method' => 'cash', 'payment_date' => '2026-03-02',
        ], $actor, 'walk-400');
        $this->assertSame('700.00', $supplier->fresh()->current_balance);
        $this->assertSame('partially_paid', $draft->fresh()->status->value);

        $service->createPayment([
            'supplier_id' => $supplier->id, 'supplier_invoice_id' => $draft->id,
            'amount' => 600, 'payment_method' => 'cash', 'payment_date' => '2026-03-03',
        ], $actor, 'walk-600');
        $this->assertSame('100.00', $supplier->fresh()->current_balance);
        $this->assertSame('paid', $draft->fresh()->status->value);

        $voided = $service->voidPayment($first, $actor, 'entered twice');
        $this->assertSame('500.00', $supplier->fresh()->current_balance);
        $this->assertSame('partially_paid', $draft->fresh()->status->value);
        $this->assertSame('600.00', $draft->fresh()->paid_amount);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame($actor->id, $voided->voided_by);
        $this->assertSame('entered twice', $voided->void_reason);
        $this->assertModelExists($voided);

        $second = $service->createInvoice([
            'supplier_id' => $supplier->id, 'supplier_invoice_no' => 'WALK-2',
            'issue_date' => '2026-03-04', 'subtotal' => 200,
        ]);
        $service->postInvoice($second);
        $this->assertSame('700.00', $supplier->fresh()->current_balance);
        $service->cancelInvoice($second->fresh());
        $this->assertSame('500.00', $supplier->fresh()->current_balance);
    }

    public function test_future_payment_hits_the_balance_immediately_and_a_covering_statement_matches(): void
    {
        $this->travelTo('2026-06-01 12:00:00');
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create(['opening_balance' => 80, 'current_balance' => 80]);
        $service = app(SupplierFinanceService::class);
        $service->createPayment([
            'supplier_id' => $supplier->id, 'amount' => 30.50, 'payment_method' => 'cash', 'payment_date' => '2026-06-15',
        ], $actor, 'future-pay');

        $this->assertSame('49.50', $supplier->fresh()->current_balance);
        $today = $service->statement($supplier->fresh(), '2026-06-01', '2026-06-01');
        $this->assertSame([], array_filter($today['entries'], fn (array $entry): bool => $entry['type'] === 'payment'));
        $covered = $service->statement($supplier->fresh(), '2026-06-01', '2026-06-15');
        $this->assertEqualsWithDelta(49.50, $covered['closing_balance'], 0.001);
        $this->assertEqualsWithDelta((float) $supplier->fresh()->current_balance, $covered['closing_balance'], 0.001);
    }

    public function test_random_supplier_ledger_sequences_keep_balance_paid_amount_and_statement_aligned(): void
    {
        $actor = User::factory()->create();
        $service = app(SupplierFinanceService::class);
        for ($sample = 0; $sample < 8; $sample++) {
            $opening = round(10 + ($sample * 1.37), 2);
            $supplier = Supplier::factory()->create(['opening_balance' => $opening, 'current_balance' => $opening]);
            $openInvoice = null;
            for ($step = 0; $step < 6; $step++) {
                $roll = ($sample * 6 + $step) % 5;
                try {
                    if ($roll === 0 || $openInvoice === null) {
                        $openInvoice = $service->createInvoice([
                            'supplier_id' => $supplier->id,
                            'supplier_invoice_no' => "RAND-{$sample}-{$step}",
                            'issue_date' => '2026-04-01',
                            'subtotal' => round(20 + (($step + 1) * 3.15), 2),
                        ]);
                        $service->postInvoice($openInvoice);
                    } elseif ($roll === 1) {
                        $remaining = round((float) $openInvoice->fresh()->total_amount - (float) $openInvoice->fresh()->paid_amount, 2);
                        if ($remaining > 0) {
                            $service->createPayment([
                                'supplier_id' => $supplier->id,
                                'supplier_invoice_id' => $openInvoice->id,
                                'amount' => round($remaining / 2, 2) > 0 ? round($remaining / 2, 2) : $remaining,
                                'payment_method' => 'cash',
                                'payment_date' => '2026-04-02',
                            ], $actor, "rand-{$sample}-{$step}");
                        }
                    } elseif ($roll === 2) {
                        $payment = SupplierPayment::query()->where('supplier_id', $supplier->id)->whereNull('voided_at')->first();
                        if ($payment !== null) {
                            $service->voidPayment($payment, $actor, 'random');
                        }
                    } elseif ($roll === 3 && (float) $openInvoice->fresh()->paid_amount === 0.0) {
                        $service->cancelInvoice($openInvoice->fresh());
                        $openInvoice = null;
                    } else {
                        $free = $service->createPayment([
                            'supplier_id' => $supplier->id, 'amount' => 1.25, 'payment_method' => 'cash', 'payment_date' => '2026-04-03',
                        ], $actor, "free-{$sample}-{$step}");
                        if ($openInvoice !== null && in_array($openInvoice->fresh()->status->value, ['posted', 'partially_paid'], true)) {
                            $service->allocatePayment($free, $openInvoice->id);
                        }
                    }
                } catch (BusinessException) {
                    // Invalid draws are skipped; the invariant still has to hold.
                }
                $this->assertSupplierLedger($service, $supplier->fresh());
            }
        }
    }

    public function test_supplier_payment_without_an_idempotency_key_is_rejected_and_writes_nothing(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $supplier = Supplier::factory()->create(['current_balance' => 40]);
        $this->exerciseHistoricalResale();

        $this->withToken($admin->createToken('pay')->plainTextToken)
            ->postJson('/api/v1/admin/supplier-payments', [
                'supplier_id' => $supplier->id,
                'amount' => 10,
                'payment_method' => 'cash',
                'payment_date' => '2026-04-01',
            ])->assertUnprocessable();

        $this->assertDatabaseCount('supplier_payments', 0);
        $this->assertSame('40.00', $supplier->fresh()->current_balance);
    }

    public function test_duplicate_receipt_lines_that_exceed_the_remainder_change_nothing(): void
    {
        $this->exerciseHistoricalResale();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $approver = User::factory()->create();
        $approver->assignRole('Super Admin');
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $variant = ProductVariant::factory()->create(['cost_price' => 80]);
        $token = $admin->createToken('po')->plainTextToken;

        $po = $this->withToken($token)->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'items' => [[
                'product_variant_id' => $variant->id,
                'ordered_qty' => 10,
                'unit_cost' => 100,
            ]],
        ], ['Idempotency-Key' => 'po-dup'])->assertCreated()->json('data');

        Auth::forgetGuards();
        $this->withToken($approver->createToken('approver')->plainTextToken)
            ->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$po['id']}/send")->assertOk();

        $itemId = $po['items'][0]['id'];
        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [
                ['purchase_order_item_id' => $itemId, 'qty' => 6],
                ['purchase_order_item_id' => $itemId, 'qty' => 6],
            ],
        ], ['Idempotency-Key' => 'recv-dup'])->assertUnprocessable();

        $this->assertSame('0.0000', (string) DB::table('purchase_order_items')->where('id', $itemId)->value('received_qty'));
        $this->assertSame('80.0000', $variant->fresh()->cost_price);
        $this->assertSame(0, StockMovement::query()->where('type', 'purchase_receipt')->count());
    }

    public function test_discounted_partial_receipt_uses_weighted_cost_then_rejects_over_receipt(): void
    {
        $this->exerciseHistoricalResale();
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $approver = User::factory()->create();
        $approver->assignRole('Super Admin');
        $branch = Branch::factory()->create();
        $supplier = Supplier::factory()->create();
        $first = ProductVariant::factory()->create(['cost_price' => 80]);
        $second = ProductVariant::factory()->create(['cost_price' => 10]);
        StockLevel::forceCreate([
            'product_variant_id' => $first->id, 'branch_id' => $branch->id,
            'on_hand' => 10, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
        ]);
        $token = $admin->createToken('po')->plainTextToken;
        $po = $this->withToken($token)->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'discount_amount' => 125,
            'items' => [
                ['product_variant_id' => $first->id, 'ordered_qty' => 10, 'unit_cost' => 100],
                ['product_variant_id' => $second->id, 'ordered_qty' => 5, 'unit_cost' => 50],
            ],
        ], ['Idempotency-Key' => 'po-disc'])->assertCreated()->json('data');
        $this->assertSame('1250.00', $po['subtotal']);

        Auth::forgetGuards();
        $this->withToken($approver->createToken('approver')->plainTextToken)
            ->postJson("/api/v1/admin/purchase-orders/{$po['id']}/approve")->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$po['id']}/send")->assertOk();

        $firstItem = $po['items'][0]['id'];
        $secondItem = $po['items'][1]['id'];
        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $firstItem, 'qty' => 4]],
        ], ['Idempotency-Key' => 'recv-4'])->assertOk()->assertJsonPath('data.status', 'partially_received');

        $this->assertSame('90.0000', $first->fresh()->last_cost_price);
        $this->assertSame('82.8571', $first->fresh()->cost_price);
        $this->assertSame('4.0000', (string) DB::table('purchase_order_items')->where('id', $firstItem)->value('received_qty'));
        $this->assertDatabaseHas('stock_movements', [
            'type' => 'purchase_receipt', 'branch_id' => $branch->id, 'quantity' => 4, 'unit_cost' => 90,
        ]);

        $before = StockMovement::query()->count();
        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $firstItem, 'qty' => 7]],
        ], ['Idempotency-Key' => 'recv-over'])->assertUnprocessable();
        $this->assertSame($before, StockMovement::query()->count());
        $this->assertSame('82.8571', $first->fresh()->cost_price);

        $this->withToken($token)->postJson("/api/v1/admin/purchase-orders/{$po['id']}/receive", [
            'items' => [
                ['purchase_order_item_id' => $firstItem, 'qty' => 6],
                ['purchase_order_item_id' => $secondItem, 'qty' => 5],
            ],
        ], ['Idempotency-Key' => 'recv-rest'])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_payment_webhook_rejects_bad_signatures_and_accepts_each_driver(): void
    {
        config([
            'webhooks.payment.hmac' => ['driver' => 'hmac', 'secret' => 'hmac-secret', 'signature_header' => 'X-Webhook-Signature', 'currency' => 'EGP'],
            'webhooks.payment.stripe' => ['driver' => 'stripe', 'secret' => 'stripe-secret', 'currency' => 'EGP', 'amount_divisor' => 100, 'require_amount' => true],
            'webhooks.payment.paymob' => ['driver' => 'paymob', 'secret' => 'paymob-secret', 'currency' => 'EGP', 'require_amount' => true],
            'webhooks.payment.moyasar' => ['driver' => 'moyasar', 'secret' => 'moyasar-secret', 'currency' => 'SAR', 'require_amount' => true],
            'webhooks.payment.empty' => ['driver' => 'hmac', 'secret' => '', 'signature_header' => 'X-Webhook-Signature'],
        ]);
        $order = Order::factory()->create(['payment_method' => 'card', 'payment_status' => 'unpaid', 'grand_total' => 100]);
        $body = json_encode(['event_id' => 'sig-1', 'order_number' => $order->order_number, 'status' => 'ignored']);

        $this->call('POST', '/api/v1/webhooks/payment/hmac', [], [], [], $this->jsonHeaders([
            'HTTP_X-Webhook-Signature' => 'sha256='.strtoupper(hash_hmac('sha256', $body, 'hmac-secret')),
        ]), $body)->assertOk();
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        $this->call('POST', '/api/v1/webhooks/payment/hmac', [], [], [], $this->jsonHeaders([
            'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $body, 'other'),
        ]), $body)->assertForbidden();
        $this->call('POST', '/api/v1/webhooks/payment/hmac', [], [], [], $this->jsonHeaders([]), $body)->assertForbidden();
        $log = WebhookLog::query()->where('status', 'invalid_signature')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->payload);
        $this->assertFalse($log->signature_valid);
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        $this->call('POST', '/api/v1/webhooks/payment/empty', [], [], [], $this->jsonHeaders([
            'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $body, 'hmac-secret'),
        ]), $body)->assertForbidden();

        $stamp = (string) time();
        $stripeBody = json_encode(['id' => 'evt_stripe', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['metadata' => ['order_number' => 'missing-order']]]]);
        $v1 = hash_hmac('sha256', $stamp.'.'.$stripeBody, 'stripe-secret');
        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], $this->jsonHeaders([
            'HTTP_STRIPE_SIGNATURE' => 't='.$stamp.',v1=deadbeef,v1='.$v1,
        ]), $stripeBody)->assertOk()->assertJsonPath('received', false);

        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], $this->jsonHeaders([
            'HTTP_STRIPE_SIGNATURE' => 't='.($stamp - 301).',v1='.$v1,
        ]), $stripeBody)->assertForbidden();
        $this->call('POST', '/api/v1/webhooks/payment/stripe', [], [], [], $this->jsonHeaders([
            'HTTP_STRIPE_SIGNATURE' => 't=soon,v1='.$v1,
        ]), $stripeBody)->assertForbidden();

        $paymob = [
            'obj' => [
                'amount_cents' => 10000, 'created_at' => '2026-06-01T00:00:00', 'currency' => 'EGP',
                'error_occured' => false, 'has_parent_transaction' => false, 'id' => 77, 'integration_id' => 1,
                'is_3d_secure' => false, 'is_auth' => false, 'is_capture' => false, 'is_refunded' => false,
                'is_standalone_payment' => true, 'is_voided' => false, 'order' => ['id' => 9], 'owner' => 1,
                'pending' => false, 'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
                'success' => false,
            ],
        ];
        $hmac = $this->paymobHmac($paymob['obj'], 'paymob-secret');
        $this->call('POST', '/api/v1/webhooks/payment/paymob?hmac='.$hmac, [], [], [], $this->jsonHeaders([]), json_encode($paymob))
            ->assertOk();
        $tampered = $paymob;
        $tampered['obj']['amount_cents'] = 10001;
        $this->call('POST', '/api/v1/webhooks/payment/paymob?hmac='.$hmac, [], [], [], $this->jsonHeaders([]), json_encode($tampered))
            ->assertForbidden();

        $moyasar = json_encode(['id' => 'moy-1', 'status' => 'ignored', 'secret_token' => 'moyasar-secret']);
        $this->call('POST', '/api/v1/webhooks/payment/moyasar', [], [], [], $this->jsonHeaders([]), $moyasar)->assertOk();
        $this->call('POST', '/api/v1/webhooks/payment/moyasar', [], [], [], $this->jsonHeaders([]), json_encode(['id' => 'moy-2', 'secret_token' => 'nope']))
            ->assertForbidden();

        $huge = str_repeat('x', 262145);
        $this->call('POST', '/api/v1/webhooks/payment/hmac', [], [], [], $this->jsonHeaders([
            'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $huge, 'hmac-secret'),
        ]), $huge)->assertStatus(413);
        $this->assertNull(WebhookLog::query()->where('status', 'rejected')->latest('id')->first()?->payload);
    }

    public function test_payment_webhook_outcomes_do_not_confirm_mismatched_or_manual_payments(): void
    {
        config(['webhooks.payment.lab' => [
            'driver' => 'hmac', 'secret' => 'lab-secret', 'signature_header' => 'X-Webhook-Signature',
            'currency' => 'EGP', 'require_amount' => true,
        ]]);
        $pending = Order::factory()->create(['payment_method' => 'card', 'payment_status' => 'unpaid', 'status' => OrderStatus::Pending, 'grand_total' => 100]);
        $this->postPayment($pending, ['event_id' => 'short', 'status' => 'paid', 'amount' => 99.99, 'currency' => 'EGP'])
            ->assertOk()->assertJsonPath('message', 'amount mismatch');
        $this->assertSame('unpaid', $pending->fresh()->payment_status);
        $this->assertTrue($pending->fresh()->needs_attention);
        $this->assertSame('failed', WebhookLog::query()->latest('id')->first()->status);

        $currency = Order::factory()->create(['payment_method' => 'card', 'payment_status' => 'unpaid', 'grand_total' => 100]);
        $this->postPayment($currency, ['event_id' => 'fx', 'status' => 'paid', 'amount' => 100, 'currency' => 'USD']);
        $this->assertSame('unpaid', $currency->fresh()->payment_status);
        $this->assertTrue($currency->fresh()->needs_attention);

        $cancelled = Order::factory()->create(['payment_method' => 'card', 'payment_status' => 'unpaid', 'status' => OrderStatus::Cancelled, 'grand_total' => 100]);
        $this->postPayment($cancelled, ['event_id' => 'late', 'status' => 'paid', 'amount' => 100, 'currency' => 'EGP'])->assertOk();
        $this->assertSame('paid', $cancelled->fresh()->payment_status);
        $this->assertTrue($cancelled->fresh()->requires_refund);
        $this->assertSame(OrderStatus::Cancelled, $cancelled->fresh()->status);

        $already = Order::factory()->create(['payment_method' => 'card', 'payment_status' => 'paid', 'status' => OrderStatus::Confirmed, 'grand_total' => 100]);
        $this->postPayment($already, ['event_id' => 'late-fail', 'status' => 'failed', 'amount' => 100])->assertOk()->assertJsonPath('ignored', true);
        $this->assertSame('paid', $already->fresh()->payment_status);

        $shipped = Order::factory()->create(['payment_method' => 'card', 'payment_status' => 'paid', 'status' => OrderStatus::Shipped, 'grand_total' => 100]);
        $this->postPayment($shipped, ['event_id' => 'refund-ship', 'status' => 'refunded', 'amount' => 100])->assertOk();
        $this->assertSame('refunded', $shipped->fresh()->payment_status);
        $this->assertTrue($shipped->fresh()->needs_attention);

        $waiting = Order::factory()->create(['payment_method' => 'card', 'payment_status' => 'unpaid', 'grand_total' => 100]);
        $this->postPayment($waiting, ['event_id' => 'pend', 'status' => 'pending'])->assertOk()->assertJsonPath('ignored', true);
        $this->assertSame('unpaid', $waiting->fresh()->payment_status);

        $this->postPayment(null, ['event_id' => 'ghost', 'order_number' => 'ORD-MISSING', 'status' => 'paid', 'amount' => 10])
            ->assertOk()->assertJsonPath('received', false);

        config(['webhooks.payment.optional' => [
            'driver' => 'hmac', 'secret' => 'lab-secret', 'signature_header' => 'X-Webhook-Signature',
            'currency' => 'EGP', 'require_amount' => false,
        ]]);
        $optional = Order::factory()->create(['payment_method' => 'card', 'payment_status' => 'unpaid', 'status' => OrderStatus::Cancelled, 'grand_total' => 40]);
        $body = json_encode(['event_id' => 'no-amount', 'order_number' => $optional->order_number, 'status' => 'paid']);
        $this->call('POST', '/api/v1/webhooks/payment/optional', [], [], [], $this->jsonHeaders([
            'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $body, 'lab-secret'),
        ]), $body)->assertOk();
        $this->assertSame('paid', $optional->fresh()->payment_status);
        $this->assertTrue($optional->fresh()->requires_refund);
    }

    public function test_non_transient_confirm_failure_keeps_the_captured_payment(): void
    {
        config(['webhooks.payment.lab' => [
            'driver' => 'hmac', 'secret' => 'lab-secret', 'signature_header' => 'X-Webhook-Signature',
            'currency' => 'EGP', 'require_amount' => true,
        ]]);
        $order = Order::factory()->create([
            'payment_method' => 'card', 'payment_status' => 'unpaid', 'status' => OrderStatus::Pending, 'grand_total' => 100,
        ]);
        $this->app->instance(OrderService::class, new class extends OrderService
        {
            public function __construct() {}

            public function changeStatus(Order $order, OrderStatus|string $toStatus, ?Model $actor = null, ?string $notes = null, ?array $expectedFrom = null, bool $returnEffects = true, bool $allowNoticesLoss = false): Order
            {
                DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'check violation' USING ERRCODE = '23514'; END $$;");

                return $order;
            }
        });

        $this->postPayment($order, ['event_id' => 'paid-but-confirm-fails', 'status' => 'paid', 'amount' => 100, 'currency' => 'EGP'])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertTrue($fresh->needs_attention);
        $this->assertSame(OrderStatus::Pending, $fresh->status);
        $log = WebhookLog::query()->where('event_id', 'like', '%paid-but-confirm-fails%')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('processed', $log->status);
        $this->assertNotNull($log->error);
    }

    public function test_integrations_check_failures_do_not_print_secrets(): void
    {
        config([
            'filesystems.disks.r2.key' => 'secret-access-key',
            'filesystems.disks.r2.secret' => 'secret-value',
            'filesystems.disks.r2.bucket' => null,
            'filesystems.disks.r2.endpoint' => 'https://r2.example.test',
            'filesystems.disks.r2.root' => 'alfarid',
            'services.gemini.api_key' => 'secret-gemini-key',
            'services.gemini.model' => 'test-model',
        ]);
        $this->assertSame(1, \Artisan::call('integrations:check'));
        $this->assertSecretsHidden(\Artisan::output());

        config([
            'filesystems.disks.r2.bucket' => 'same-bucket',
            'filesystems.disks.r2_public.bucket' => 'same-bucket',
            'filesystems.disks.r2_public.url' => 'https://catalog.example.test',
            'filesystems.catalog_disk' => 'r2_public',
        ]);
        $this->assertSame(1, \Artisan::call('integrations:check'));
        $this->assertSecretsHidden(\Artisan::output());

        config([
            'filesystems.disks.r2.bucket' => 'private-bucket',
            'filesystems.disks.r2_public.bucket' => 'public-bucket',
        ]);
        Storage::fake('r2');
        Storage::disk('r2')->buildTemporaryUrlsUsing(fn (): string => 'https://signed.example/check.png');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jBZkAAAAASUVORK5CYII=');
        Http::fake([
            'https://signed.example/*' => Http::response($png, 200),
            'generativelanguage.googleapis.com/*' => Http::response('denied', 403),
        ]);
        $this->assertSame(1, \Artisan::call('integrations:check', ['--live' => true]));
        $output = \Artisan::output();
        $this->assertSecretsHidden($output);
        $this->assertStringContainsString('HTTP 403', $output);
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    private function assertSupplierLedger(SupplierFinanceService $service, Supplier $supplier): void
    {
        $posted = (float) SupplierInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', ['posted', 'partially_paid', 'paid'])
            ->sum('total_amount');
        $paid = (float) SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->whereNull('voided_at')
            ->sum('amount');
        $expected = round((float) $supplier->opening_balance + $posted - $paid, 2);
        $this->assertEqualsWithDelta($expected, (float) $supplier->current_balance, 0.001);

        foreach (SupplierInvoice::query()->where('supplier_id', $supplier->id)->get() as $invoice) {
            $allocated = (float) SupplierPayment::query()
                ->where('supplier_invoice_id', $invoice->id)
                ->whereNull('voided_at')
                ->sum('amount');
            $this->assertEqualsWithDelta($allocated, (float) $invoice->paid_amount, 0.001);
            $this->assertLessThanOrEqual((float) $invoice->total_amount + 0.001, (float) $invoice->paid_amount);
        }

        $statement = $service->statement($supplier, '2026-01-01', '2026-12-31');
        $this->assertEqualsWithDelta((float) $supplier->current_balance, $statement['closing_balance'], 0.001);
    }

    private function postPayment(?Order $order, array $payload): TestResponse
    {
        $payload['order_number'] ??= $order?->order_number;
        $body = json_encode($payload);
        $gateway = str_contains($payload['event_id'] ?? '', 'no-amount') ? 'optional' : 'lab';

        return $this->call('POST', '/api/v1/webhooks/payment/'.$gateway, [], [], [], $this->jsonHeaders([
            'HTTP_X-Webhook-Signature' => hash_hmac('sha256', $body, 'lab-secret'),
        ]), $body);
    }

    /** @param  array<string, string>  $headers */
    private function jsonHeaders(array $headers): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$headers];
    }

    /** @param  array<string, mixed>  $tx */
    private function paymobHmac(array $tx, string $secret): string
    {
        $fields = [
            'amount_cents', 'created_at', 'currency', 'error_occured',
            'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
            'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
            'is_voided', 'order.id', 'owner', 'pending',
            'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
        ];
        $concatenated = '';
        foreach ($fields as $field) {
            $value = data_get($tx, $field);
            $concatenated .= is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return hash_hmac('sha512', $concatenated, $secret);
    }

    private function assertSecretsHidden(string $output): void
    {
        $this->assertStringNotContainsString('secret-access-key', $output);
        $this->assertStringNotContainsString('secret-value', $output);
        $this->assertStringNotContainsString('secret-gemini-key', $output);
        $this->assertStringNotContainsString('https://r2.example.test', $output);
    }
}
