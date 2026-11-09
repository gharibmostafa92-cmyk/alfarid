<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Wholesale\QuotationStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

class TaskTxtQuotationAcceptRaceTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

    private Customer $trader;

    private string $traderToken;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 100000,
        ]);
        $this->traderToken = $this->trader->createToken('portal')->plainTextToken;

        $product = Product::factory()->create(['status' => 'published']);
        $this->variant = $product->variants()->first() ?? ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->variant->update(['retail_price' => 100, 'wholesale_price' => null, 'is_active' => true]);

        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create()->id,
            'product_variant_id' => $this->variant->id,
            'on_hand' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        // The committed reservation ledger references the migration's system user.
        $this->artisan('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    private function asTrader(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->traderToken);
    }

    private function pricedQuotation(): Quotation
    {
        $id = $this->asTrader()->postJson('/api/v1/wholesale/quotations', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'qty' => 2],
            ],
        ])->assertCreated()->json('data.id');

        $quotation = Quotation::query()->findOrFail($id);
        $quotation->update(['status' => QuotationStatus::PricedByAdmin]);

        return $quotation;
    }

    public function test_accept_locks_quotation_row_for_update_inside_transaction(): void
    {
        $quotation = $this->pricedQuotation();

        DB::enableQueryLog();

        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept")->assertOk();

        $locked = collect(DB::getQueryLog())->contains(
            fn ($entry) => str_contains((string) $entry['query'], 'quotations')
                && str_contains(strtolower((string) $entry['query']), 'for update')
        );

        $this->assertTrue($locked, 'accept() must SELECT the quotation row FOR UPDATE so concurrent accepts serialize.');
    }

    public function test_second_accept_is_rejected_and_creates_no_duplicate_order(): void
    {
        $quotation = $this->pricedQuotation();

        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept")->assertOk();

        $this->asTrader()->postJson("/api/v1/wholesale/quotations/{$quotation->id}/accept")->assertStatus(422);

        $this->assertSame(
            1,
            Order::query()->where('customer_id', $this->trader->id)->count(),
            'A quotation must convert to exactly one order.'
        );
        $this->assertNotNull($quotation->fresh()->converted_order_id);
    }

    public function test_parallel_accepts_create_only_one_order_and_stock_reservation(): void
    {
        $quotation = $this->pricedQuotation();
        $job = $this->httpJob($this->traderToken, 'POST', "/api/v1/wholesale/quotations/{$quotation->id}/accept");
        $results = $this->concurrentJobs([$job, $job]);

        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'status'));
        $order = Order::query()->where('customer_id', $this->trader->id)->sole();
        $this->assertSame($order->id, $quotation->fresh()->converted_order_id);
        $this->assertSame(2.0, (float) StockLevel::query()
            ->where('product_variant_id', $this->variant->id)->sole()->reserved);
    }
}
