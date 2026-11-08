<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PriceListType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Services\PricingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * task.txt item 27: أولوية اختيار السعر (PricingService)
 */
class TaskTxtPricingPriorityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function makeCustomer(
        CustomerType $type = CustomerType::Wholesale,
        CustomerStatus $status = CustomerStatus::Approved,
        ?int $groupId = null,
    ): Customer {
        return Customer::query()->create([
            'name' => 'تاجر '.uniqid(),
            'email' => uniqid().'@example.com',
            'phone' => '010'.random_int(10000000, 99999999),
            'password' => Hash::make('password123456'),
            'company_name' => 'شركة اختبار',
            'tax_number' => '100200300',
            'commercial_register' => '400500',
            'type' => $type,
            'status' => $status,
            'customer_group_id' => $groupId,
        ]);
    }

    private function makeList(PriceListType $type, array $attributes = []): PriceList
    {
        return PriceList::query()->create(array_merge([
            'name' => 'قائمة '.uniqid(),
            'type' => $type,
            'currency' => 'EGP',
            'priority' => 0,
            'is_active' => true,
        ], $attributes));
    }

    public function test_hierarchy_order_specific_then_group_then_channel_then_retail_then_base_and_group_discount(): void
    {
        $service = app(PricingService::class);
        $variant = ProductVariant::factory()->create([
            'retail_price' => 200,
            'wholesale_price' => 160,
            'is_active' => true,
        ]);

        $group = CustomerGroup::query()->create([
            'name' => 'مجموعة ذهبية',
            'code' => 'GOLD-'.uniqid(),
            'discount_percentage' => 10,
            'is_active' => true,
        ]);
        $customer = $this->makeCustomer(CustomerType::Wholesale, CustomerStatus::Approved, $group->id);

        // 1. Base wholesale price + 10% group discount -> 160 * 0.9 = 144.0
        $res = $service->resolve($variant, $customer);
        $this->assertSame(144.0, $res['unit_price']);
        $this->assertSame('customer_group_discount', $res['source']);

        // 2. Retail fallback list (when no wholesale list exists) + 10% group discount -> 150 * 0.9 = 135.0
        $retailList = $this->makeList(PriceListType::Retail);
        $retailList->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 150]);
        $res = $service->resolve($variant, $customer->fresh());
        $this->assertSame(135.0, $res['unit_price']);
        $this->assertSame('customer_group_discount', $res['source']);
        $this->assertSame($retailList->id, $res['price_list_id']);

        // 3. Wholesale channel list beats retail list + 10% group discount -> 120 * 0.9 = 108.0
        $wholesaleList = $this->makeList(PriceListType::Wholesale);
        $wholesaleList->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 120]);
        $res = $service->resolve($variant, $customer->fresh());
        $this->assertSame(108.0, $res['unit_price']);
        $this->assertSame('customer_group_discount', $res['source']);
        $this->assertSame($wholesaleList->id, $res['price_list_id']);

        // 4. Customer group list beats channel list (no double group discount) -> 100.0
        $groupList = $this->makeList(PriceListType::CustomerGroup, ['customer_group_id' => $group->id]);
        $groupList->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 100]);
        $res = $service->resolve($variant, $customer->fresh());
        $this->assertSame(100.0, $res['unit_price']);
        $this->assertSame('customer_group', $res['source']);

        // 5. Customer-specific list beats group list -> 90.0
        $specificList = $this->makeList(PriceListType::CustomerSpecific, ['customer_id' => $customer->id]);
        $specificList->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 90]);
        $res = $service->resolve($variant, $customer->fresh());
        $this->assertSame(90.0, $res['unit_price']);
        $this->assertSame('customer_specific', $res['source']);
    }

    public function test_date_windows_branch_precedence_priority_min_qty_inactive_group_and_unapproved_customer(): void
    {
        $service = app(PricingService::class);
        $variant = ProductVariant::factory()->create(['retail_price' => 200, 'wholesale_price' => 150]);
        $branch = Branch::factory()->create();

        $inactiveGroup = CustomerGroup::query()->create([
            'name' => 'مجموعة معطلة',
            'code' => 'OFF-'.uniqid(),
            'discount_percentage' => 25,
            'is_active' => false,
        ]);
        $customer = $this->makeCustomer(CustomerType::Wholesale, CustomerStatus::Approved, $inactiveGroup->id);

        // Future list and expired list are ignored
        $future = $this->makeList(PriceListType::Wholesale, ['starts_at' => now()->addDay()]);
        $future->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 50]);

        $expired = $this->makeList(PriceListType::Wholesale, ['ends_at' => now()->subDay()]);
        $expired->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 55]);

        // Inactive group's list and discount are ignored -> falls back to base wholesale_price 150
        $inactiveGroupList = $this->makeList(PriceListType::CustomerGroup, ['customer_group_id' => $inactiveGroup->id]);
        $inactiveGroupList->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 60]);
        $this->assertSame(150.0, $service->resolve($variant, $customer)['unit_price']);

        // Global list with priority 1 vs priority 5, and min_qty tiers
        $lowPrio = $this->makeList(PriceListType::Wholesale, ['priority' => 1]);
        $lowPrio->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 130]);
        $lowPrio->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 10, 'price' => 110]);

        $highPrio = $this->makeList(PriceListType::Wholesale, ['priority' => 5]);
        $highPrio->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 140]);

        // At qty=1, priority 5 beats priority 1 even though 140 > 130
        $this->assertSame(140.0, $service->resolve($variant, $customer, 1)['unit_price']);
        // At qty=10, higher min_qty (10) beats min_qty (1) regardless of priority
        $this->assertSame(110.0, $service->resolve($variant, $customer, 10)['unit_price']);

        // Branch-specific list beats global list when branchId is provided
        $branchList = $this->makeList(PriceListType::Wholesale, ['branch_id' => $branch->id, 'priority' => 0]);
        $branchList->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 125]);
        $this->assertSame(125.0, $service->resolve($variant, $customer, 1, $branch->id)['unit_price']);

        // Unapproved wholesale customer ignores customer_specific and wholesale lists, gets retail
        $unapproved = $this->makeCustomer(CustomerType::Wholesale, CustomerStatus::Pending);
        $unapprovedSpecific = $this->makeList(PriceListType::CustomerSpecific, ['customer_id' => $unapproved->id]);
        $unapprovedSpecific->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 70]);
        $this->assertSame(200.0, $service->resolve($variant, $unapproved, 1)['unit_price']);
    }

    public function test_unpriced_variant_and_invalid_quantities_throw_business_exception(): void
    {
        $service = app(PricingService::class);
        $unpriced = ProductVariant::factory()->create(['retail_price' => null, 'wholesale_price' => null]);

        try {
            $service->resolve($unpriced);
            $this->fail('Expected BusinessException for unpriced variant.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('غير مُسعَّر', $e->getMessage());
        }

        $priced = ProductVariant::factory()->create(['retail_price' => 100]);
        foreach ([0.0, -5.0, INF, NAN] as $invalidQty) {
            try {
                $service->resolve($priced, null, $invalidQty);
                $this->fail("Expected BusinessException for invalid qty: {$invalidQty}");
            } catch (BusinessException $e) {
                $this->assertSame('كمية التسعير يجب أن تكون موجبة.', $e->getMessage());
            }
        }
    }

    public function test_property_preloaded_in_memory_context_matches_sql_query_across_random_scenarios(): void
    {
        mt_srand(20261108);
        $service = app(PricingService::class);

        for ($i = 0; $i < 20; $i++) {
            $variant = ProductVariant::factory()->create([
                'retail_price' => mt_rand(8000, 25000) / 100,
                'wholesale_price' => mt_rand(0, 1) ? mt_rand(5000, 20000) / 100 : null,
            ]);
            $group = mt_rand(0, 1) ? CustomerGroup::query()->create([
                'name' => 'G-'.uniqid(),
                'code' => 'C-'.uniqid(),
                'discount_percentage' => mt_rand(0, 20),
                'is_active' => (bool) mt_rand(0, 1),
            ]) : null;
            $customer = $this->makeCustomer(
                mt_rand(0, 1) ? CustomerType::Wholesale : CustomerType::Distributor,
                mt_rand(0, 4) > 0 ? CustomerStatus::Approved : CustomerStatus::Pending,
                $group?->id,
            );

            foreach ([PriceListType::Retail, PriceListType::Wholesale, PriceListType::Distributor, PriceListType::CustomerGroup, PriceListType::CustomerSpecific] as $type) {
                if (mt_rand(0, 1) === 0) {
                    continue;
                }
                $attrs = ['priority' => mt_rand(0, 5), 'is_active' => mt_rand(0, 4) > 0];
                if ($type === PriceListType::CustomerGroup) {
                    if ($group === null) {
                        continue;
                    }
                    $attrs['customer_group_id'] = $group->id;
                }
                if ($type === PriceListType::CustomerSpecific) {
                    $attrs['customer_id'] = $customer->id;
                }
                $list = $this->makeList($type, $attrs);
                foreach ([1, 5, 20] as $minQty) {
                    if (mt_rand(0, 1) === 1) {
                        $list->items()->create([
                            'product_variant_id' => $variant->id,
                            'min_qty' => $minQty,
                            'price' => mt_rand(3000, 22000) / 100,
                        ]);
                    }
                }
            }

            $ctx = $service->contextFor($customer, [$variant->id]);
            foreach ([1.0, 5.0, 12.0, 25.0] as $qty) {
                $fromSql = $service->resolve($variant, $customer, $qty);
                $fromMemory = $service->resolve($variant, $customer, $qty, null, $ctx);
                $this->assertSame($fromSql, $fromMemory, "Scenario {$i} at qty {$qty} diverged between SQL and preloaded ctx.");
            }

            $this->assertSame(
                $service->tiersFor($variant, $customer),
                $service->tiersFor($variant, $customer, $ctx),
                "Scenario {$i} tiersFor diverged between SQL and preloaded ctx."
            );
        }
    }
}
