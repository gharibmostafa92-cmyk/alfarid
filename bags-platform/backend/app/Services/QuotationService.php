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
     * @param  Customer  $customer  Wholesale customer requesting the quote.
     * @param  array{customer_notes?: string|null, items: list<array{product_variant_id: int, qty: float, requested_target_price?: float|null}>}  $data
     * @return Quotation Newly created draft quotation.
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
     * @param  Quotation  $quotation  Draft quotation to update.
     * @param  array{customer_notes?: string|null, items?: list<array{product_variant_id: int, qty: float, requested_target_price?: float|null}>}  $data
     * @return Quotation Updated quotation instance.
     *
     * @throws BusinessException If quotation is not in draft state.
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
     * @param  Quotation  $quotation  Draft quotation to submit.
     * @return Quotation Submitted quotation instance.
     *
     * @throws BusinessException If quotation is not draft or contains no items.
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
     * @param  Quotation  $quotation  Requested quotation to price.
     * @param  array{valid_until?: string|null, admin_notes?: string|null, discount_amount?: float|null, tax_amount?: float|null, items?: list<array{id: int, offered_unit_price: float}>}  $data
     * @return Quotation Priced quotation instance.
     *
     * @throws BusinessException If quotation is not in Requested state or references invalid items.
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
     * @param  Quotation  $quotation  Priced quotation to accept.
     * @return Quotation Converted quotation with loaded relation to generated order.
     *
     * @throws BusinessException If quotation is expired, unpriced, or breaches customer credit limit.
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

            // Lock customer account row to serialize concurrent order placements and prevent credit limit race conditions.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
            $outstanding = app(WholesalePaymentNoticeService::class)->outstandingBalance($customer->fresh());
            if ($outstanding + (float) $quotation->grand_total > (float) $customer->credit_limit) {
                throw new BusinessException(
                    'إجمالي الطلب يتجاوز حد الائتمان المتاح.',
                    [],
                    422,
                    'credit_limit_exceeded',
                );
            }

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

            // Apportion header-level discount and tax across items using largest remainder to preserve exact penny reconciliation.
            $weights = $quotation->items->map(fn ($item) => (float) $item->line_total)->all();
            $discountShares = ProportionalDistribution::distribute((float) $quotation->discount_amount, $weights);
            $taxShares = ProportionalDistribution::distribute((float) $quotation->tax_amount, $weights);

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

            // Quotations hold zero inventory reservations; physical stock allocation occurs immediately upon conversion.
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
     * Reject a submitted or priced quotation.
     *
     * @param  Quotation  $quotation  Target quotation.
     * @return Quotation Rejected quotation instance.
     *
     * @throws BusinessException If quotation is not in Requested or PricedByAdmin state.
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
     * @param  Quotation  $quotation  Target quotation.
     * @return Quotation Expired quotation instance.
     *
     * @throws BusinessException If quotation is not in PricedByAdmin state or has not reached its valid_until date.
     */
    public function expire(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::PricedByAdmin) {
            throw new BusinessException('لا يمكن إنهاء صلاحية عرض السعر إلا بعد تسعيره.');
        }

        if ($quotation->valid_until !== null && $quotation->valid_until->gte(now()->startOfDay())) {
            throw new BusinessException('عرض السعر ما زال ساري الصلاحية.');
        }

        $quotation->update(['status' => QuotationStatus::Expired]);

        return $quotation->load('items.variant.product');
    }

    /**
     * Synchronize requested line items and initial pricing for a quotation.
     *
     * @param  Quotation  $quotation  Parent quotation instance.
     * @param  Customer  $customer  Customer instance to resolve tiered pricing.
     * @param  list<array{product_variant_id: int, qty: float, requested_target_price?: float|null}>  $items
     *
     * @throws BusinessException If any variant is non-existent or inactive.
     */
    private function syncItems(Quotation $quotation, Customer $customer, array $items): void
    {
        $variantIds = array_column($items, 'product_variant_id');
        $variants = ProductVariant::query()
            ->whereIn('id', $variantIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        foreach ($items as $row) {
            $variant = $variants->get($row['product_variant_id']);

            if ($variant === null) {
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

    /**
     * Recalculate quotation subtotal and grand total.
     *
     * @param  Quotation  $quotation
     *
     * @throws BusinessException If discount exceeds gross total resulting in a negative total.
     */
    private function recalculate(Quotation $quotation): void
    {
        $subtotal = (float) $quotation->items()->sum('line_total');
        $grandTotal = round($subtotal - (float) $quotation->discount_amount + (float) $quotation->tax_amount, 2);

        // Grand total cannot drop below zero; negative sums indicate an excessive discount that violates accounting boundaries.
        if ($grandTotal < 0) {
            throw new BusinessException('إجمالي عرض السعر لا يمكن أن يكون سالبًا — راجع الخصم.');
        }

        $quotation->update([
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
        ]);
    }
}
