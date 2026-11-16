<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\QuotationStatus;
use App\Exceptions\BusinessException;
use App\Jobs\SendOutboundMessage;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\CustomerWholesaleAssistant;
use App\Services\Wholesale\QuotationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TraderBotOrderHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_accept_button_cannot_convert_a_revision_written_after_it_reads_the_quotation(): void
    {
        $customer = $this->trader();
        $variant = $this->stockedVariant();
        $assistant = app(CustomerWholesaleAssistant::class);
        $quotes = app(QuotationService::class);
        $quoted = $quotes->price($quotes->submit($quotes->create($customer, [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 2]],
        ])), []);
        $button = "accept:{$quoted->id}:".$assistant->acceptHash($quoted);
        $revisionChanged = false;
        DB::listen(function (QueryExecuted $query) use ($quoted, &$revisionChanged): void {
            if ($revisionChanged || ! str_starts_with($query->sql, 'select * from "quotations"')
                || str_contains($query->sql, 'for update')) {
                return;
            }
            $revisionChanged = true;
            Quotation::query()->whereKey($quoted->id)->increment('revision');
        });

        $assistant->reply($customer, '', $button);

        $this->assertTrue($revisionChanged, 'The revision must change after the button reads the quotation.');
        $this->assertSame(QuotationStatus::PricedByAdmin, $quoted->fresh()->status);
        $this->assertDatabaseMissing('orders', ['customer_id' => $customer->id]);
    }

    public function test_balance_uses_the_earliest_active_credit_due_date(): void
    {
        $customer = $this->trader();
        foreach ([
            ['credit', OrderStatus::Cancelled, '2026-10-10'],
            ['cod', OrderStatus::Confirmed, '2026-10-11'],
            ['credit', OrderStatus::Confirmed, '2026-10-12'],
        ] as [$method, $status, $due]) {
            Order::factory()->create([
                'customer_id' => $customer->id,
                'payment_method' => $method,
                'payment_status' => 'unpaid',
                'status' => $status,
                'grand_total' => 150,
                'due_at' => $due,
            ]);
        }

        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'رصيدي');

        $this->assertStringContainsString('2026-10-12', $reply);
    }

    public function test_quote_button_rolls_back_draft_when_submission_fails(): void
    {
        $customer = $this->trader();
        $variant = $this->stockedVariant();
        $assistant = app(CustomerWholesaleAssistant::class);
        $assistant->reply($customer, 'عايز 2 '.$variant->sku);
        $this->failNextQuoteSubmission();

        $assistant->reply($customer, 'عرض سعر');

        $this->assertDatabaseMissing('quotations', ['customer_id' => $customer->id]);
    }

    public function test_quote_button_can_retry_the_same_selection_after_failure(): void
    {
        $customer = $this->trader();
        $variant = $this->stockedVariant();
        $assistant = app(CustomerWholesaleAssistant::class);
        $assistant->reply($customer, 'عايز 2 '.$variant->sku);
        $this->failNextQuoteSubmission();

        $assistant->reply($customer, 'عرض سعر');
        $assistant->reply($customer, 'عرض سعر');
        $assistant->reply($customer, 'عرض سعر');

        $this->assertSame(
            [QuotationStatus::Requested->value],
            Quotation::query()->where('customer_id', $customer->id)->pluck('status')->map->value->all(),
        );
    }

    public function test_failed_otp_can_be_replaced_while_pending_otp_is_reused(): void
    {
        Bus::fake([SendOutboundMessage::class]);
        $this->freezeTime();
        DB::table('settings')->where('key', 'ai_wholesale_shadow_mode')->update(['value' => '0']);
        app(SettingService::class)->clearCache();
        $customer = $this->trader(verified: false);
        $assistant = app(CustomerWholesaleAssistant::class);
        $assistant->reply($customer, 'أرسل الرمز');
        $first = OutboundMessage::query()->where('template', 'customer_otp')->sole();

        $assistant->reply($customer, 'أرسل الرمز');
        $this->assertDatabaseCount('outbound_messages', 1);

        $first->update(['status' => 'error', 'error' => 'otp_provider_error', 'payload' => []]);
        $assistant->reply($customer, 'أرسل الرمز');

        $this->assertDatabaseHas('outbound_messages', ['template' => 'customer_otp', 'status' => 'pending']);
        $second = OutboundMessage::query()->where('template', 'customer_otp')->where('status', 'pending')->sole();
        $this->assertNotSame($first->id, $second->id);
        $assistant->reply($customer, 'أرسل الرمز');
        $this->assertDatabaseCount('outbound_messages', 2);
        $code = $second->payload['code'];
        $this->assertStringContainsString('تم ربط الرقم', $assistant->reply($customer, $code));
    }

    public function test_explicit_quantity_after_a_numeric_sku_preserves_the_sku(): void
    {
        $customer = $this->trader();
        $variant = $this->stockedVariant('2024');
        $assistant = app(CustomerWholesaleAssistant::class);

        $reply = $assistant->reply($customer, 'عايز ٢٠٢٤ عدد ٥٠');

        $this->assertStringContainsString('× 50 بسعر', $reply);
        $assistant->reply($customer, 'عرض سعر');
        $this->assertDatabaseHas('quotation_items', ['product_variant_id' => $variant->id, 'qty' => '50.00']);
    }

    public function test_spoken_plural_thousands_are_used_as_the_requested_count(): void
    {
        $customer = $this->trader();
        $this->stockedVariant();

        $reply = app(CustomerWholesaleAssistant::class)->reply($customer, 'عايز تلات آلاف قطعة WH-2024');

        $this->assertStringContainsString('× 3000 بسعر', $reply);
    }

    public function test_explicit_numeric_code_resolves_with_a_product_description_and_quantity(): void
    {
        $customer = $this->trader();
        $variant = $this->stockedVariant('2024');
        $assistant = app(CustomerWholesaleAssistant::class);

        $reply = $assistant->reply($customer, 'عايز شنطة كود ٢٠٢٤ كمية ٥٠');

        $this->assertStringContainsString('× 50 بسعر', $reply);
        $assistant->reply($customer, 'عرض سعر');
        $this->assertDatabaseHas('quotation_items', ['product_variant_id' => $variant->id, 'qty' => '50.00']);
    }

    public function test_out_of_bounds_counts_are_not_eligible_for_cart_or_quote_actions(): void
    {
        $customer = $this->trader();
        $variant = $this->stockedVariant();

        $assistant = app(CustomerWholesaleAssistant::class);
        $reply = $assistant->reply($customer, 'عايز 1000001 '.$variant->sku);

        $this->assertStringNotContainsString('[عرض سعر]', $reply);
        $assistant->reply($customer, 'عرض سعر');
        $this->assertDatabaseMissing('quotations', ['customer_id' => $customer->id]);
    }

    private function trader(bool $verified = true): Customer
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 50000,
        ]);
        if ($verified) {
            CustomerChannel::query()->create([
                'customer_id' => $customer->id,
                'provider' => 'whatsapp_cloud',
                'phone' => CustomerChannel::canonicalPhone($customer->phone),
                'verification_status' => 'verified',
                'verified_at' => now(),
            ]);
        }

        return $customer;
    }

    private function stockedVariant(string $sku = 'WH-2024'): ProductVariant
    {
        $product = Product::factory()->published()->create(['sales_channel' => 'both']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $variant->update(['sku' => $sku, 'retail_price' => 100, 'wholesale_price' => 80, 'is_active' => true]);
        StockLevel::forceCreate([
            'branch_id' => Branch::factory()->create(['is_active' => true])->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 1000,
        ]);

        return $variant;
    }

    private function failNextQuoteSubmission(): void
    {
        $fail = true;
        Quotation::saving(function (Quotation $quotation) use (&$fail): void {
            if ($fail && $quotation->status === QuotationStatus::Requested) {
                $fail = false;
                throw new BusinessException('Temporary submission failure.');
            }
        });
    }
}
