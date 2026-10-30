<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\SalesChannel;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockLevel;
use Illuminate\Database\Seeder;

/**
 * بيانات اختبار بوابة الجملة (task 0.3):
 * تاجر معتمد + تاجر pending + تاجر مرفوض، وقائمة أسعار بشرائح،
 * ومنتجات منشورة لقناة الجملة مع مخزون، وطلبات بحالات مختلفة.
 */
class WholesaleDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('Wholesale demo data cannot be seeded in production.');
        }

        $branch = Branch::query()->where('is_active', true)->first()
            ?? Branch::factory()->create(['is_active' => true]);

        $group = CustomerGroup::firstOrCreate(
            ['code' => 'WHOLESALE-A'],
            ['name' => 'تجار الجملة — فئة أ', 'discount_percentage' => 0, 'is_active' => true],
        );

        $approved = $this->trader([
            'email' => 'wholesale.demo@alfarid.test',
            'status' => CustomerStatus::Approved,
            'credit_limit' => 250000,
            'approved_at' => now(),
        ], $group->id);
        foreach (['commercial_register', 'tax_card', 'national_id'] as $type) {
            CustomerDocument::create([
                'customer_id' => $approved->id,
                'document_type' => $type,
                'file_path' => "wholesale-demo/{$type}.pdf",
                'original_name' => "{$type}.pdf",
                'mime_type' => 'application/pdf',
                'status' => 'verified',
            ]);
        }

        $this->trader([
            'email' => 'wholesale.pending@alfarid.test',
            'status' => CustomerStatus::Pending,
        ], $group->id);

        $this->trader([
            'email' => 'wholesale.rejected@alfarid.test',
            'status' => CustomerStatus::Rejected,
            'rejection_reason' => 'المستندات غير مكتملة — أعد رفع السجل التجاري.',
        ], $group->id);

        // both بدل wholesale فقط: سلة الـ store تقبل retail/both فقط،
        // والكتالوج يعرض wholesale/both معًا.
        $products = Product::factory()->count(4)->published()->create([
            'sales_channel' => SalesChannel::Both,
        ]);

        $variants = collect();
        foreach ($products as $product) {
            $variant = $product->variants()->first()
                ?? \App\Models\ProductVariant::factory()->create(['product_id' => $product->id]);
            $variant->update(['retail_price' => 1000, 'wholesale_price' => 900, 'is_active' => true]);
            StockLevel::forceCreate([
                'branch_id' => $branch->id,
                'product_variant_id' => $variant->id,
                'on_hand' => 500,
                'reserved' => 0,
            ]);
            $variants->push($variant->fresh());
        }

        $list = PriceList::create([
            'name' => 'قائمة فئة أ — تجريبية',
            'type' => 'customer_group',
            'customer_group_id' => $group->id,
            'currency' => 'EGP',
            'priority' => 1,
            'is_active' => true,
        ]);
        foreach ($variants as $variant) {
            foreach ([[1, 900], [24, 860], [60, 820]] as [$minQty, $price]) {
                $list->items()->create([
                    'product_variant_id' => $variant->id,
                    'min_qty' => $minQty,
                    'price' => $price,
                ]);
            }
        }

        $this->order($approved, $variants[0], OrderStatus::Pending, 'credit', 'unpaid');
        $this->order($approved, $variants[1], OrderStatus::Processing, 'credit', 'unpaid');
        $this->order($approved, $variants[0], OrderStatus::Delivered, 'credit', 'unpaid');
        $this->order($approved, $variants[1], OrderStatus::Delivered, 'bank_transfer', 'paid');
        $this->order($approved, $variants[2], OrderStatus::Cancelled, 'credit', 'unpaid');
    }

    private function trader(array $attributes, int $groupId): Customer
    {
        return Customer::updateOrCreate(
            ['email' => $attributes['email']],
            array_merge([
                'name' => 'تاجر تجريبي',
                'phone' => '010'.fake()->numerify('########'),
                'password' => bcrypt('password'),
                'type' => CustomerType::Wholesale,
                'company_name' => 'شركة الاختبار للتجارة',
                'tax_number' => fake()->unique()->numerify('#########'),
                'commercial_register' => fake()->unique()->numerify('######'),
                'customer_group_id' => $groupId,
            ], $attributes),
        );
    }

    private function order(Customer $customer, $variant, OrderStatus $status, string $method, string $pay): Order
    {
        $order = Order::create([
            'order_number' => 'WS-'.fake()->unique()->numerify('######'),
            'channel' => 'wholesale',
            'customer_id' => $customer->id,
            'guest_name' => $customer->name,
            'guest_phone' => $customer->phone,
            'status' => $status,
            'payment_method' => $method,
            'payment_status' => $pay,
            'subtotal' => 5000,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'grand_total' => 5000,
        ]);
        $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'منتج تجريبي',
            'sku_snapshot' => $variant->sku,
            'qty' => 5,
            'unit_price' => 1000,
            'discount_amount' => 0,
            'line_total' => 5000,
        ]);
        $order->statusHistories()->create([
            'from_status' => null,
            'to_status' => $status,
            'changed_by_type' => Customer::class,
            'changed_by_id' => $customer->id,
        ]);

        return $order;
    }
}
