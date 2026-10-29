<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Events\OrderCreated;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manages the wholesale quotation lifecycle:
 * Draft creation with dynamic pricing -> customer submission -> administrator pricing and validation ->
 * customer acceptance with automated order generation and stock allocation, or rejection/expiry.
 *
 * Quotations hold no inventory reservations; stock allocation occurs only upon customer acceptance.
 */
class QuotationService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly PricingService $pricing,
        private readonly OrderService $orders,
        private readonly OrderAllocationService $allocation,
    ) {}

    /**
     * Create a new draft quotation for a wholesale customer.
     *
     * @param  Customer  $customer
     * @param  array{customer_notes?: string|null, items: list<array{product_variant_id: int, qty: float, requested_target_price?: float|null}>}  $data
     * @return Quotation
     */
    public function create(Customer $customer, array $data): Quotation
    {
        return DB::transaction(function () use ($customer, $data): Quotation {
            $quotation = Quotation::create([
                'quotation_number' => $this->settings->generateNextNumber('quotation'),
                'customer_id' => $customer->id,
                'status' => QuotationStatus::Draft,
                'customer_notes' => $data['customer_notes'] ?? null,
            ]);

            $this->syncItems($quotation, $customer, $data['items']);
            $this->recalculate($quotation);

            return $quotation->load('items.variant.product');
        });
    }

    /**
     * Update items and customer notes on an unsubmitted draft quotation.
     *
     * @param  Quotation  $quotation
     * @param  array{customer_notes?: string|null, items?: list<array{product_variant_id: int, qty: float, requested_target_price?: float|null}>}  $data
     * @return Quotation
     *
     * @throws BusinessException
     */
    public function update(Quotation $quotation, array $data): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw new BusinessException('لا يمكن تعديل عرض السعر إلا وهو مسودة.');
        }

        return DB::transaction(function () use ($quotation, $data): Quotation {
            $quotation->update([
                'customer_notes' => $data['customer_notes'] ?? $quotation->customer_notes,
            ]);

            if (isset($data['items'])) {
                $quotation->items()->delete();
                $this->syncItems($quotation, $quotation->customer, $data['items']);
            }

            $this->recalculate($quotation);

            return $quotation->load('items.variant.product');
        });
    }

    /**
     * Submit a draft quotation for administrator pricing and review.
     *
     * @param  Quotation  $quotation
     * @return Quotation
     *
     * @throws BusinessException
     */
    public function submit(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw new BusinessException('لا يمكن إرسال عرض السعر إلا من حالة المسودة.');
        }

        if ($quotation->items()->count() === 0) {
            throw new BusinessException('لا يمكن إرسال عرض سعر بلا بنود.');
        }

        $quotation->update(['status' => QuotationStatus::Requested]);

        return $quotation->load('items.variant.product');
    }

    /**
     * Set negotiated prices, validity expiration, discounts, and taxes by an administrator.
     *
     * @param  Quotation  $quotation
     * @param  array{valid_until?: string|null, admin_notes?: string|null, discount_amount?: float|null, tax_amount?: float|null, items?: list<array{id: int, offered_unit_price: float}>}  $data
     * @return Quotation
     *
     * @throws BusinessException
     */
    public function price(Quotation $quotation, array $data): Quotation
    {
        if ($quotation->status !== QuotationStatus::Requested) {
            throw new BusinessException('لا يمكن تسعير عرض السعر إلا بعد إرساله من التاجر.');
        }

        return DB::transaction(function () use ($quotation, $data): Quotation {
            foreach ($data['items'] ?? [] as $row) {
                $item = $quotation->items()->whereKey($row['id'])->first();

                if ($item === null) {
                    throw new BusinessException('أحد بنود التسعير لا ينتمي لعرض السعر هذا.');
                }

                $item->update([
                    'offered_unit_price' => $row['offered_unit_price'],
                    'line_total' => round((float) $row['offered_unit_price'] * (float) $item->qty, 2),
                ]);
            }

            $quotation->update([
                'status' => QuotationStatus::PricedByAdmin,
                'valid_until' => $data['valid_until'] ?? $quotation->valid_until,
                'admin_notes' => $data['admin_notes'] ?? $quotation->admin_notes,
                'discount_amount' => $data['discount_amount'] ?? $quotation->discount_amount,
                'tax_amount' => $data['tax_amount'] ?? $quotation->tax_amount,
            ]);

            $this->recalculate($quotation);

            return $quotation->load('items.variant.product');
        });
    }

    /**
     * Accept an administrator-priced quotation and automatically convert it into a confirmed order.
     *
     * Preserves negotiated line prices, distributes header discounts/taxes using Hare-Niemeyer,
     * and allocates inventory. Fails the conversion entirely if any item lacks available stock.
     *
     * @param  Quotation  $quotation
     * @return Quotation
     *
     * @throws BusinessException
     */
    public function accept(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::PricedByAdmin) {
            throw new BusinessException('لا يمكن قبول عرض السعر إلا بعد تسعير الإدارة له.');
        }

        if ($quotation->valid_until !== null && $quotation->valid_until->lt(now()->startOfDay())) {
            $quotation->update(['status' => QuotationStatus::Expired]);

            throw new BusinessException('انتهت صلاحية عرض السعر هذا.');
        }

        return DB::transaction(function () use ($quotation): Quotation {
            $quotation->loadMissing(['customer', 'items.variant.product']);
            $customer = $quotation->customer;

            $order = Order::create([
                'order_number' => $this->settings->generateNextNumber('order'),
                'channel' => 'wholesale',
                'customer_id' => $customer->id,
                'guest_name' => $customer->name,
                'guest_phone' => $customer->phone,
                'guest_phone_normalized' => Customer::normalizePhone((string) $customer->phone),
                'guest_email' => $customer->email,
                'status' => OrderStatus::Pending,
                'payment_method' => 'credit',
                'payment_status' => 'unpaid',
                'subtotal' => $quotation->subtotal,
                'discount_amount' => $quotation->discount_amount,
                'shipping_cost' => 0,
                'cod_fee' => 0,
                'tax_amount' => $quotation->tax_amount,
                'grand_total' => $quotation->grand_total,
            ]);

            // Distribute header discount and tax to items using largest remainder for exact cent precision
            $weights = $quotation->items->map(fn ($item) => (float) $item->line_total)->all();
            $discountShares = $this->distributeLargestRemainder((float) $quotation->discount_amount, $weights);
            $taxShares = $this->distributeLargestRemainder((float) $quotation->tax_amount, $weights);

            foreach ($quotation->items->values() as $i => $item) {
                $order->items()->create([
                    'product_variant_id' => $item->product_variant_id,
                    'product_name_snapshot' => $item->variant->product?->name_ar ?? '',
                    'sku_snapshot' => $item->variant->sku,
                    'qty' => $item->qty,
                    'unit_price' => $item->offered_unit_price,
                    'discount_amount' => $discountShares[$i],
                    'tax_amount' => $taxShares[$i],
                    'line_total' => $item->line_total,
                ]);
            }

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::Pending,
                'changed_by_type' => Customer::class,
                'changed_by_id' => $customer->id,
                'notes' => "أُنشئ من عرض السعر {$quotation->quotation_number}",
            ]);

            $order->load('items');
            $this->allocation->allocate($order, performedBy: $this->systemActorId());

            $this->orders->changeStatus($order, OrderStatus::Confirmed, $customer, "قبول عرض السعر {$quotation->quotation_number}");

            $quotation->update([
                'status' => QuotationStatus::ConvertedToOrder,
                'converted_order_id' => $order->id,
            ]);

            OrderCreated::dispatch($order->fresh());

            return $quotation->load(['items.variant.product', 'convertedOrder']);
        });
    }

    /**
     * Retrieve the designated system user ID for recording customer-initiated ledger movements.
     */
    private function systemActorId(): ?int
    {
        $id = User::query()
            ->where('is_system', true)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Distribute an amount proportionally across weights using the Largest Remainder (Hare-Niemeyer) method.
     *
     * Ensures line item shares sum exactly to the header amount with zero rounding discrepancies.
     *
     * @param  float  $amount
     * @param  list<float>  $weights
     * @return list<float>
     */
    private function distributeLargestRemainder(float $amount, array $weights): array
    {
        $count = count($weights);
        $totalCents = (int) round($amount * 100);
        $totalWeight = array_sum($weights);

        if ($count === 0 || $totalCents === 0 || $totalWeight <= 0) {
            return array_fill(0, $count, 0.0);
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $i => $weight) {
            $exact = $totalCents * $weight / $totalWeight;
            $floor = (int) floor($exact);
            $shares[$i] = $floor;
            $remainders[$i] = $exact - $floor;
            $allocated += $floor;
        }

        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b): int => $remainders[$b] <=> $remainders[$a]);
        for ($k = 0; $k < $totalCents - $allocated; $k++) {
            $shares[$order[$k % $count]]++;
        }

        return array_map(fn (int $cents): float => $cents / 100, $shares);
    }

    /**
     * Reject a submitted or priced quotation.
     *
     * @param  Quotation  $quotation
     * @return Quotation
     *
     * @throws BusinessException
     */
    public function reject(Quotation $quotation): Quotation
    {
        if (! in_array($quotation->status, [QuotationStatus::Requested, QuotationStatus::PricedByAdmin], true)) {
            throw new BusinessException('لا يمكن رفض عرض السعر في حالته الحالية.');
        }

        $quotation->update(['status' => QuotationStatus::Rejected]);

        return $quotation->load('items.variant.product');
    }

    /**
     * Expire a past-due quotation.
     *
     * @param  Quotation  $quotation
     * @return Quotation
     *
     * @throws BusinessException
     */
    public function expire(Quotation $quotation): Quotation
    {
        if (! in_array($quotation->status, [QuotationStatus::Requested, QuotationStatus::PricedByAdmin], true)) {
            throw new BusinessException('لا يمكن إنهاء صلاحية عرض السعر في حالته الحالية.');
        }

        $quotation->update(['status' => QuotationStatus::Expired]);

        return $quotation->load('items.variant.product');
    }

    /**
     * Delete an unsubmitted draft quotation.
     *
     * @param  Quotation  $quotation
     * @return void
     *
     * @throws BusinessException
     */
    public function deleteDraft(Quotation $quotation): void
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw new BusinessException('لا يمكن حذف عرض السعر إلا وهو مسودة.');
        }

        $quotation->delete();
    }

    /**
     * @param  list<array{product_variant_id: int, qty: float, requested_target_price?: float|null}>  $items
     */
    private function syncItems(Quotation $quotation, Customer $customer, array $items): void
    {
        $variantIds = collect($items)->pluck('product_variant_id')->unique()->all();
        $variants = ProductVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

        foreach ($items as $row) {
            $variant = $variants->get($row['product_variant_id']);

            if ($variant === null || ! $variant->is_active) {
                throw new BusinessException('أحد المتغيرات المطلوبة غير موجود أو غير نشط.');
            }

            $offered = $this->pricing->resolve($variant, $customer, (float) $row['qty'])['unit_price'];

            $quotation->items()->create([
                'product_variant_id' => $variant->id,
                'qty' => $row['qty'],
                'requested_target_price' => $row['requested_target_price'] ?? null,
                'offered_unit_price' => $offered,
                'line_total' => round($offered * (float) $row['qty'], 2),
            ]);
        }
    }

    private function recalculate(Quotation $quotation): void
    {
        $subtotal = (float) $quotation->items()->sum('line_total');
        $grandTotal = round($subtotal - (float) $quotation->discount_amount + (float) $quotation->tax_amount, 2);

        if ($grandTotal < 0) {
            throw new BusinessException('إجمالي عرض السعر لا يمكن أن يكون سالبًا — راجع الخصم.');
        }

        $quotation->update([
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
        ]);
    }
}
