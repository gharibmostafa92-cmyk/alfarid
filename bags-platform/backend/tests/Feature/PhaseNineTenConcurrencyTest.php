<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReturnService;
use App\Services\SupplierFinanceService;
use App\Services\WalletService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
