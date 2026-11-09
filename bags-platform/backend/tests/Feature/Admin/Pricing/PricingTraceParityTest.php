<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Catalog\PriceListType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PricingTraceParityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_trace_final_matches_resolve_across_random_scenarios(): void
    {
        $service = app(PricingService::class);
        $branch = Branch::factory()->create();

        for ($i = 0; $i < 200; $i++) {
            $variant = ProductVariant::factory()->create([
                'retail_price' => fake()->randomFloat(2, 10, 2000),
                'wholesale_price' => fake()->boolean(80) ? fake()->randomFloat(2, 5, 1500) : null,
            ]);

            $customer = $this->randomCustomer();
            $qty = (float) fake()->randomElement([1, 1, 2, 5, 10, 25]);
            $branchId = fake()->boolean(30) ? $branch->id : null;

            $this->maybeSeedLists($variant, $customer, $branch->id);

            $expected = $this->tryResolve($service, $variant, $customer, $qty, $branchId);
            $actual = $this->tryResolveTrace($service, $variant, $customer, $qty, $branchId);

            if ($expected === null) {
                $this->assertNull($actual, "scenario {$i}: trace should throw when resolve throws");
                continue;
            }

            $this->assertNotNull($actual, "scenario {$i}: trace should not throw when resolve succeeds");
            $this->assertSame($expected, $actual['final'], "scenario {$i} mismatch");
            $this->assertNotEmpty($actual['layers']);
            $this->assertIsArray($actual['notes']);
        }
    }

    private function randomCustomer(): ?Customer
    {
        $roll = fake()->numberBetween(1, 100);
        if ($roll <= 25) {
            return null;
        }
        $type = fake()->randomElement([CustomerType::Retail, CustomerType::Wholesale, CustomerType::Distributor]);
        $status = fake()->randomElement([CustomerStatus::Approved, CustomerStatus::Pending, CustomerStatus::Suspended]);
        $group = null;
        if (fake()->boolean(40)) {
            $group = CustomerGroup::query()->create([
                'name' => 'G '.uniqid(),
                'code' => 'G'.uniqid(),
                'discount_percentage' => (string) fake()->randomElement([0, 0, 5, 10, 15]),
                'is_active' => fake()->boolean(80),
            ]);
        }

        return Customer::query()->create([
            'name' => 'عميل '.uniqid(),
            'email' => uniqid().'@example.com',
            'phone' => '010'.random_int(10000000, 99999999),
            'company_name' => 'Company',
            'tax_number' => '123',
            'commercial_register' => '456',
            'password' => Hash::make('password'),
            'type' => $type,
            'status' => $status,
            'customer_group_id' => $group?->id,
        ]);
    }

    private function maybeSeedLists(ProductVariant $variant, ?Customer $customer, int $branchId): void
    {
        $types = [PriceListType::Retail, PriceListType::Wholesale, PriceListType::Distributor];
        foreach ($types as $type) {
            if (! fake()->boolean(45)) {
                continue;
            }
            $list = PriceList::query()->create([
                'name' => 'L '.uniqid(),
                'type' => $type,
                'branch_id' => fake()->boolean(25) ? $branchId : null,
                'priority' => fake()->numberBetween(0, 5),
                'is_active' => fake()->boolean(85),
                'starts_at' => fake()->boolean(20) ? now()->addDay() : null,
                'ends_at' => null,
            ]);
            $list->items()->create([
                'product_variant_id' => $variant->id,
                'min_qty' => (float) fake()->randomElement([1, 1, 5, 10]),
                'price' => fake()->randomFloat(2, 5, 1800),
            ]);
        }

        if ($customer !== null && fake()->boolean(35)) {
            $list = PriceList::query()->create([
                'name' => 'S '.uniqid(),
                'type' => PriceListType::CustomerSpecific,
                'customer_id' => $customer->id,
                'priority' => 0,
            ]);
            $list->items()->create([
                'product_variant_id' => $variant->id,
                'min_qty' => 1,
                'price' => fake()->randomFloat(2, 5, 1800),
            ]);
        }

        $groupId = $customer?->customer_group_id;
        if ($groupId !== null && fake()->boolean(50)) {
            $list = PriceList::query()->create([
                'name' => 'CG '.uniqid(),
                'type' => PriceListType::CustomerGroup,
                'customer_group_id' => $groupId,
                'priority' => 0,
            ]);
            $list->items()->create([
                'product_variant_id' => $variant->id,
                'min_qty' => 1,
                'price' => fake()->randomFloat(2, 5, 1800),
            ]);
        }
    }

    private function tryResolve(PricingService $service, ProductVariant $variant, ?Customer $customer, float $qty, ?int $branchId): ?array
    {
        try {
            return $service->resolve($variant, $customer, $qty, $branchId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function tryResolveTrace(PricingService $service, ProductVariant $variant, ?Customer $customer, float $qty, ?int $branchId): ?array
    {
        try {
            return $service->trace($variant, $customer, $qty, $branchId);
        } catch (\Throwable) {
            return null;
        }
    }
}
