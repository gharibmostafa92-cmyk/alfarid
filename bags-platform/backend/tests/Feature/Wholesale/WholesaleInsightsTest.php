<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OutboundMessage;
use App\Models\Quotation;
use App\Services\Wholesale\WholesaleInsights;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WholesaleInsightsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_credit_suggestions_flag_a_payer_near_the_limit(): void
    {
        $customer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 10000,
        ]);
        Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'subtotal' => 9500,
            'grand_total' => 9500,
            'due_at' => now()->addDays(5),
        ]);

        $rows = app(WholesaleInsights::class)->creditSuggestions();

        $this->assertNotEmpty($rows);
        $this->assertSame($customer->id, $rows[0]['customer_id']);
        $this->assertGreaterThanOrEqual(90.0, (float) $rows[0]['used_percent']);
    }

    public function test_collection_priorities_rank_by_score_with_a_draft(): void
    {
        $late = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000, 'company_name' => 'المتأخر الكبير']);
        $small = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000, 'company_name' => 'المتأخر الصغير']);
        $big = Order::factory()->create([
            'customer_id' => $late->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'subtotal' => 50000,
            'grand_total' => 50000,
            'due_at' => now()->subDays(10),
        ]);
        Order::factory()->create([
            'customer_id' => $small->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Confirmed,
            'subtotal' => 1000,
            'grand_total' => 1000,
            'due_at' => now()->subDays(2),
        ]);

        $rows = app(WholesaleInsights::class)->collectionPriorities();

        $this->assertSame($big->order_number, $rows[0]['order_number']);
        $this->assertStringContainsString($big->order_number, $rows[0]['draft']);
    }

    public function test_payment_reminders_respect_the_weekly_cap(): void
    {
        $capped = Customer::factory()->create([
            'type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000, 'whatsapp_opt_in_at' => now(),
        ]);
        $fresh = Customer::factory()->create([
            'type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 100000, 'whatsapp_opt_in_at' => now(),
        ]);
        foreach ([$capped, $fresh] as $customer) {
            Order::factory()->create([
                'customer_id' => $customer->id,
                'payment_method' => 'credit',
                'payment_status' => 'unpaid',
                'status' => OrderStatus::Confirmed,
                'subtotal' => 1000,
                'grand_total' => 1000,
                'due_at' => now()->addDay(),
            ]);
        }
        for ($i = 0; $i < 3; $i++) {
            OutboundMessage::query()->create([
                'customer_id' => $capped->id,
                'channel' => 'customer_whatsapp',
                'idempotency_key' => "cap-proof-{$i}",
                'status' => 'sent',
                'template' => 'payment_reminder',
                'payload' => ['order_number' => 'X'],
            ]);
        }

        $this->artisan('wholesale:payment-reminders')->assertSuccessful();

        $this->assertSame(3, OutboundMessage::query()->where('customer_id', $capped->id)->where('template', 'payment_reminder')->count());
        $this->assertSame(1, OutboundMessage::query()->where('customer_id', $fresh->id)->where('template', 'payment_reminder')->count());
    }

    public function test_quote_acceptance_reports_the_rate(): void
    {
        Quotation::factory()->create(['status' => QuotationStatus::Accepted]);
        Quotation::factory()->create(['status' => QuotationStatus::Rejected]);

        $metric = app(WholesaleInsights::class)->quoteAcceptance();

        $this->assertSame(1, $metric['accepted']);
        $this->assertSame(2, $metric['closed']);
        $this->assertSame(50.0, $metric['acceptance_rate']);
    }
}
