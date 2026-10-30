<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Http\Middleware\ManufacturingBusiness;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\ReturnService;
use App\Services\SupplierFinanceService;
use App\Services\WalletService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PhaseNineTenConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $staff;

    private Branch $branch;

    private Supplier $supplier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->staff = User::factory()->create();
        $this->branch = Branch::factory()->create();
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 100]);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    private function po(array $variants): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['supplier_id' => $this->supplier->id, 'branch_id' => $this->branch->id, 'created_by' => $this->staff->id, 'status' => 'sent_to_supplier', 'discount_amount' => 0, 'subtotal' => count($variants) * 13000]);
        foreach ($variants as $variant) {
            $po->items()->create(['product_variant_id' => $variant->id, 'ordered_qty' => 100, 'received_qty' => 0, 'unit_cost' => 130, 'line_total' => 13000, 'tax_rate' => 0]);
        }

        return $po->fresh(['items']);
    }

    private function receiptJob(PurchaseOrder $po, array $items, string $key): string
    {
        return 'app(App\\Services\\ProcurementService::class)->receivePurchaseOrder(App\\Models\\PurchaseOrder::findOrFail('.$po->id.'), '.var_export($items, true).', performedBy: '.$this->staff->id.', idempotencyKey: '.var_export($key, true).');';
    }

    public function test_concurrent_identical_receipts_post_one_inventory_movement(): void
    {
        $po = $this->po([$this->variant]);
        $job = $this->receiptJob($po, [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 40]], 'same-receipt');
        $this->race([$job, $job]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame(40.0, (float) StockLevel::sum('on_hand'));
        $this->assertSame('40.0000', $po->items->first()->fresh()->received_qty);
    }

    public function test_concurrent_identical_payments_debit_supplier_once(): void
    {
        $data = ['supplier_id' => $this->supplier->id, 'amount' => 40, 'payment_method' => 'cash', 'payment_date' => '2026-10-19'];
        $job = 'app(App\\Services\\SupplierFinanceService::class)->createPayment('.var_export($data, true).', App\\Models\\User::findOrFail('.$this->staff->id.'), "same-payment");';
        $this->race([$job, $job]);
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertSame('-40.00', $this->supplier->fresh()->current_balance);
    }

    public function test_supplier_payment_api_serializes_identical_concurrent_requests(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->staff->assignRole('Super Admin');
        $token = $this->staff->createToken('checklist-concurrency')->plainTextToken;
        $data = ['supplier_id' => $this->supplier->id, 'amount' => 40, 'payment_method' => 'cash', 'payment_date' => now()->toDateString()];
        $job = '$request = Illuminate\\Http\\Request::create("/api/v1/admin/supplier-payments", "POST", '.var_export($data, true).', [], [], '.var_export([
            'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_IDEMPOTENCY_KEY' => 'api-same-payment',
        ], true).');';
        $job .= <<<'PHP'
// The retained procurement rules are tested below the deliberate 410 retirement gate.
$app->instance(App\Http\Middleware\ManufacturingBusiness::class, new class extends App\Http\Middleware\ManufacturingBusiness {
    public function handle(Illuminate\Http\Request $request, Closure $next): Symfony\Component\HttpFoundation\Response { return $next($request); }
});
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
if (in_array($response->getStatusCode(), [200, 201], true)) {
    $id = json_decode($response->getContent(), true)['data']['id'] ?? null;
    if ($id === null || (int) $id !== (int) App\Models\SupplierPayment::sole()->id) {
        throw new RuntimeException('Payment replay returned another document.');
    }
} elseif ($response->getStatusCode() !== 409 || $response->headers->get('Retry-After') !== '1') {
    throw new RuntimeException('Unexpected API response: '.$response->getStatusCode().' '.$response->getContent());
}
$kernel->terminate($request, $response);
PHP;
        $this->race([$job, $job]);
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertDatabaseCount('operation_idempotency_keys', 1);
        $this->assertSame('-40.00', $this->supplier->fresh()->current_balance);
    }

    public function test_payment_api_returns_retryable_conflict_when_another_connection_holds_the_key(): void
    {
        $this->withoutMiddleware(ManufacturingBusiness::class);
        $this->seed(RolePermissionSeeder::class);
        $this->staff->assignRole('Super Admin');
        $data = ['supplier_id' => $this->supplier->id, 'amount' => 40, 'payment_method' => 'cash', 'payment_date' => now()->toDateString()];
        app(SupplierFinanceService::class)->createPayment($data, $this->staff, 'locked-payment');
        config(['database.connections.checklist_locker' => config('database.connections.pgsql')]);
        $locker = DB::connection('checklist_locker');
        $previous = DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value;
        $locker->beginTransaction();
        try {
            $locker->table('operation_idempotency_keys')->where('key', 'locked-payment')->lockForUpdate()->firstOrFail();
            $started = microtime(true);
            $this->withToken($this->staff->createToken('checklist')->plainTextToken)
                ->postJson('/api/v1/admin/supplier-payments', $data, ['Idempotency-Key' => 'locked-payment'])
                ->assertConflict()->assertHeader('Retry-After', '1');
            $this->assertGreaterThanOrEqual(2.5, microtime(true) - $started);
        } finally {
            $locker->rollBack();
            DB::purge('checklist_locker');
        }
        $this->assertSame($previous, DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value);
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertDatabaseCount('operation_idempotency_keys', 1);
        $this->assertSame('-40.00', $this->supplier->fresh()->current_balance);
    }

    public function test_receipts_with_opposite_variant_order_do_not_deadlock(): void
    {
        $second = ProductVariant::factory()->create(['is_active' => true, 'cost_price' => 80]);
        $po1 = $this->po([$this->variant, $second]);
        $po2 = $this->po([$second, $this->variant]);
        $jobs = [];
        foreach ([$po1, $po2] as $po) {
            $items = $po->items->map(fn ($item) => ['purchase_order_item_id' => $item->id, 'qty' => 10])->all();
            $jobs[] = $this->receiptJob($po, $items, 'po-'.$po->id);
        }
        $this->race($jobs);
        $this->assertDatabaseCount('stock_movements', 4);
        $this->assertSame(40.0, (float) StockLevel::sum('on_hand'));
    }

    public function test_concurrent_invoice_cancellation_and_payment_keep_balance_consistent(): void
    {
        $invoice = app(SupplierFinanceService::class)->createInvoice(['supplier_id' => $this->supplier->id, 'supplier_invoice_no' => 'RACE-1', 'issue_date' => '2026-10-19', 'subtotal' => 100]);
        app(SupplierFinanceService::class)->postInvoice($invoice);
        $data = ['supplier_id' => $this->supplier->id, 'supplier_invoice_id' => $invoice->id, 'amount' => 40, 'payment_method' => 'cash', 'payment_date' => '2026-10-19'];
        $cancel = 'app(App\\Services\\SupplierFinanceService::class)->cancelInvoice(App\\Models\\SupplierInvoice::findOrFail('.$invoice->id.'));';
        $pay = 'app(App\\Services\\SupplierFinanceService::class)->createPayment('.var_export($data, true).', App\\Models\\User::findOrFail('.$this->staff->id.'), "race-payment");';
        $this->race([$cancel, $pay], ['OK', 'REJECTED']);
        $invoice->refresh();
        $expectedBalance = $invoice->status->value === 'cancelled' ? '0.00' : '60.00';
        $this->assertSame($expectedBalance, $this->supplier->fresh()->current_balance);
        $this->assertSame((float) $invoice->paid_amount, (float) $invoice->payments()->sum('amount'));
    }

    public function test_receipt_wins_against_stale_return_delete_without_losing_audit_document(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'customer_id' => Customer::factory()->create()->id]);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'product_variant_id' => $this->variant->id, 'qty' => 1]);
        $order->statusHistories()->create(['to_status' => OrderStatus::Delivered]);
        $return = app(ReturnService::class)->createReturnRequest($order, ['reason' => 'test', 'items' => [['order_item_id' => $item->id, 'requested_qty' => 1]]]);
        app(ReturnService::class)->approve($return, $this->staff, ['branch_id' => $this->branch->id]);
        $receiveData = ['items' => [['id' => $return->items->first()->id, 'received_good_qty' => 1, 'received_damaged_qty' => 0]]];
        $receiver = '$prefix = getenv("INVENTORY_START_FILE"); $deadline = microtime(true) + 8; while (!is_file($prefix.".stale")) { if(microtime(true)>$deadline)throw new RuntimeException("No stale read"); usleep(10000); } ';
        $receiver .= 'Illuminate\\Support\\Facades\\DB::transaction(function () use ($prefix) { app(App\\Services\\ReturnService::class)->receive(App\\Models\\ReturnRequest::findOrFail('.$return->id.'), App\\Models\\User::findOrFail('.$this->staff->id.'), '.var_export($receiveData, true).'); touch($prefix.".left"); $deadline=microtime(true)+8; while(!is_file($prefix.".right")){if(microtime(true)>$deadline)throw new RuntimeException("No deletion contender");usleep(10000);} });';
        $deleter = '$prefix=getenv("INVENTORY_START_FILE"); $stale=App\\Models\\ReturnRequest::findOrFail('.$return->id.'); touch($prefix.".stale"); $deadline=microtime(true)+8; while(!is_file($prefix.".left")){if(microtime(true)>$deadline)throw new RuntimeException("No receipt");usleep(10000);}touch($prefix.".right"); app(App\\Services\\ReturnService::class)->delete($stale);';
        $this->race([$receiver, $deleter], ['OK', 'REJECTED']);
        $this->assertDatabaseHas('return_requests', ['id' => $return->id, 'status' => 'received']);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_concurrent_wallet_debits_cannot_spend_the_same_balance_twice(): void
    {
        $customer = Customer::factory()->create();
        app(WalletService::class)->record($customer->id, 100, 'opening', 'customer', $customer->id);
        $jobs = [];
        foreach ([1, 2] as $reference) {
            $jobs[] = 'app(App\\Services\\WalletService::class)->record('.$customer->id.', -80, "checkout_payment", "order", '.$reference.');';
        }
        $this->race($jobs, ['OK', 'REJECTED']);
        $this->assertSame('20.00', $customer->fresh()->wallet_balance);
        $this->assertDatabaseCount('customer_wallet_transactions', 2);
    }

    public function test_concurrent_processed_webhooks_are_deduplicated_by_postgresql(): void
    {
        $job = <<<'PHP'
try {
    Illuminate\Support\Facades\DB::transaction(fn () => Illuminate\Support\Facades\DB::table('webhook_logs')->insert([
        'provider' => 'checklist', 'event_id' => 'same-event', 'status' => 'processed', 'payload' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]));
} catch (Illuminate\Database\UniqueConstraintViolationException) {
    throw new App\Exceptions\BusinessException('Duplicate processed webhook.');
}
PHP;
        $this->race([$job, $job], ['OK', 'REJECTED']);
        $this->assertDatabaseCount('webhook_logs', 1);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_concurrent_notice_submissions_keep_one_reference_and_receipt(bool $linked): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_method' => 'credit', 'payment_status' => 'unpaid', 'grand_total' => 1000]);
        $data = ['amount' => 400, 'payment_method' => 'bank_transfer', 'bank_name' => 'Checklist bank', 'reference_number' => 'CONCURRENT-REFERENCE', 'transfer_date' => now()->toDateString()];
        if ($linked) {
            $data['order_id'] = $order->id;
        }
        $token = $customer->createToken('checklist')->plainTextToken;
        $job = 'config(["filesystems.disks.r2" => ["driver" => "local", "root" => storage_path("framework/testing/disks/r2"), "throw" => true]]);';
        $job .= '$request = Illuminate\\Http\\Request::create("/api/v1/wholesale/payment-notices", "POST", '.var_export($data, true).', [], ["receipt" => Illuminate\\Http\\UploadedFile::fake()->image("receipt.jpg")], '.var_export([
            'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], true).');';
        $job .= <<<'PHP'
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
if ($response->getStatusCode() === 409) {
    throw new App\Exceptions\BusinessException('Duplicate reference.');
}
if ($response->getStatusCode() !== 201) {
    throw new RuntimeException('Unexpected payment notice response: '.$response->getStatusCode().' '.$response->getContent());
}
PHP;
        $this->race([$job, $job], ['OK', 'REJECTED']);
        $this->assertDatabaseCount('wholesale_payment_notices', 1);
        $this->assertCount(1, Storage::disk('r2')->allFiles());
        $this->assertDatabaseCount('treasury_transactions', 0);
    }

    public function test_concurrent_different_receipt_keys_cannot_over_receive_the_order(): void
    {
        $po = $this->po([$this->variant]);
        $items = [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 60]];
        $this->race([$this->receiptJob($po, $items, 'receipt-a'), $this->receiptJob($po, $items, 'receipt-b')], ['OK', 'REJECTED']);
        $this->assertSame('60.0000', $po->items->first()->fresh()->received_qty);
        $this->assertSame(60.0, (float) StockLevel::sum('on_hand'));
        $this->assertDatabaseCount('operation_idempotency_keys', 1);
    }

    public function test_concurrent_payments_with_different_keys_cannot_overpay_one_invoice(): void
    {
        $service = app(SupplierFinanceService::class);
        $invoice = $service->createInvoice(['supplier_id' => $this->supplier->id, 'supplier_invoice_no' => 'CAPACITY', 'issue_date' => now()->toDateString(), 'subtotal' => 100]);
        $service->postInvoice($invoice);
        $data = ['supplier_id' => $this->supplier->id, 'supplier_invoice_id' => $invoice->id, 'amount' => 80, 'payment_method' => 'cash', 'payment_date' => now()->toDateString()];
        $jobs = [];
        foreach (['capacity-a', 'capacity-b'] as $key) {
            $jobs[] = 'app(App\\Services\\SupplierFinanceService::class)->createPayment('.var_export($data, true).', App\\Models\\User::findOrFail('.$this->staff->id.'), '.var_export($key, true).');';
        }
        $this->race($jobs, ['OK', 'REJECTED']);
        $this->assertSame('80.00', $invoice->fresh()->paid_amount);
        $this->assertSame('20.00', $this->supplier->fresh()->current_balance);
        $this->assertDatabaseCount('supplier_payments', 1);
    }

    public function test_concurrent_return_requests_cannot_exceed_the_purchased_quantity(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Delivered, 'customer_id' => Customer::factory()->create()->id]);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'product_variant_id' => $this->variant->id, 'qty' => 3]);
        $order->statusHistories()->create(['to_status' => OrderStatus::Delivered]);
        $data = ['reason' => 'checklist', 'items' => [['order_item_id' => $item->id, 'requested_qty' => 2]]];
        $job = 'app(App\\Services\\ReturnService::class)->createReturnRequest(App\\Models\\Order::findOrFail('.$order->id.'), '.var_export($data, true).');';
        $this->race([$job, $job], ['OK', 'REJECTED']);
        $this->assertDatabaseCount('return_requests', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(2, (int) DB::table('return_request_items')->sum('requested_qty'));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_concurrent_notice_approvals_conserve_treasury_receipts_and_fifo_allocations(bool $sameNotice): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->staff->assignRole('Super Admin');
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved']);
        $orders = [];
        foreach ([300, 500, 200] as $index => $total) {
            $orders[] = Order::factory()->create(['customer_id' => $customer->id, 'payment_method' => 'credit', 'payment_status' => 'unpaid', 'status' => OrderStatus::Delivered, 'grand_total' => $total, 'created_at' => now()->subDays(3 - $index)]);
        }
        $treasury = Treasury::create(['name' => 'Checklist bank', 'code' => 'CHECKLIST', 'type' => 'bank_account', 'current_balance' => 0, 'is_active' => true]);
        $notice = WholesalePaymentNotice::factory()->create(['customer_id' => $customer->id, 'amount' => $sameNotice ? 900 : 400]);
        $second = $sameNotice ? $notice : WholesalePaymentNotice::factory()->create(['customer_id' => $customer->id, 'amount' => 400]);
        $jobs = array_map(fn ($entry) => 'app(App\\Services\\WholesalePaymentNoticeService::class)->approve(App\\Models\\WholesalePaymentNotice::findOrFail('.$entry->id.'), '.$this->staff->id.', '.$treasury->id.');', [$notice, $second]);
        $this->race($jobs, $sameNotice ? ['OK', 'REJECTED'] : ['OK', 'OK']);
        $this->assertDatabaseCount('treasury_transactions', $sameNotice ? 1 : 2);
        $this->assertSame($sameNotice ? '900.00' : '800.00', $treasury->fresh()->current_balance);
        $this->assertSame(800.0, (float) DB::table('wholesale_payment_allocations')->sum('amount'));
        $this->assertSame(['paid', 'paid', 'unpaid'], array_map(fn ($order) => $order->fresh()->payment_status, $orders));
    }

    private function race(array $jobs, array $expected = ['OK', 'OK']): void
    {
        $barrier = tempnam(sys_get_temp_dir(), 'inventory-race-');
        unlink($barrier);
        $db = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $db['database'],
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'INVENTORY_START_FILE' => $barrier];
        $processes = [];
        try {
            foreach ($jobs as $operation) {
                $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::statement("SET lock_timeout = '7s'");
echo "READY\n";
$deadline = microtime(true) + 12;
while (!is_file(getenv('INVENTORY_START_FILE'))) {
    if (microtime(true) > $deadline) { exit(4); }
    usleep(10000);
}
try {
PHP;
                $script .= $operation;
                $script .= <<<'PHP'
echo "OK\n";
} catch (App\Exceptions\BusinessException $exception) {
    echo "REJECTED\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
                $process = new Process([PHP_BINARY, '-r', $script], base_path(), $environment, timeout: 15);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $process->getErrorOutput());
            }
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = trim(str_replace('READY', '', $process->getOutput()));
            }
            $this->assertEqualsCanonicalizing($expected, $results);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ([$barrier, $barrier.'.left', $barrier.'.right', $barrier.'.stale'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
