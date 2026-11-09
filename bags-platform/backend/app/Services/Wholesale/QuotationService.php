<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Services\Orders\OrderAllocationService;
use App\Services\Orders\OrderService;
use App\Services\Pricing\PricingService;
use App\Services\Shared\ProportionalDistribution;
use App\Services\Shared\SettingService;

use App\Enums\Orders\OrderStatus;
use App\Enums\Wholesale\QuotationSource;
use App\Enums\Wholesale\QuotationStatus;
use App\Events\OrderCreated;
use App\Events\Wholesale\QuotationAccepted;
use App\Events\Wholesale\QuotationExpired;
use App\Events\Wholesale\QuotationPriced;
use App\Events\Wholesale\QuotationSubmitted;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Models\User;
use Carbon\Carbon;
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
            $source = QuotationSource::tryFrom((string) ($data['source'] ?? 'portal')) ?? QuotationSource::Portal;
            $quotation = Quotation::create([
                'quotation_number' => $this->settings->generateNextNumber('quotation'),
                'customer_id' => $customer->id,
                'status' => QuotationStatus::Draft,
                'source' => $source,
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
                'customer_notes' => array_key_exists('customer_notes', $data) ? $data['customer_notes'] : $quotation->customer_notes,
            ]);

            if (isset($data['items'])) {
                $quotation->items()->delete();
                $this->syncItems($quotation, $quotation->customer, $data['items']);
                $quotation->increment('revision');
            }

            $this->recalculate($quotation);

            return $quotation->load('items.variant.product');
        });
    }

    /**
     * Delete a draft quotation along with its items.
     *
     * @param  Quotation  $quotation  Draft quotation to delete.
     *
     * @throws BusinessException If quotation is not in draft state.
     */
    public function deleteDraft(Quotation $quotation): void
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw new BusinessException('لا يمكن حذف عرض السعر إلا وهو مسودة.');
        }

        DB::transaction(function () use ($quotation): void {
            $quotation->items()->delete();
            $quotation->delete();
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
        QuotationSubmitted::dispatch($quotation);

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
            $quotation = Quotation::query()->whereKey($quotation->getKey())->lockForUpdate()->firstOrFail();

            if ($quotation->status !== QuotationStatus::Requested) {
                throw new BusinessException('لا يمكن تسعير عرض السعر إلا بعد إرساله من التاجر.');
            }

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
                'priced_at' => now(),
                'priced_by' => $data['priced_by'] ?? $quotation->priced_by,
                // Every pricing is a new revision, including the first and any
                // re-pricing after an undo: the revision feeds the priced
                // notification idempotency key, so reusing it would swallow
                // the new message (or abort the transaction on the key clash).
                'revision' => (int) $quotation->revision + 1,
            ]);

            $this->recalculate($quotation);
            $quotation = $quotation->load('items.variant.product');
            QuotationPriced::dispatch($quotation);

            return $quotation;
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
            // Re-fetch the row under a write lock and re-verify state inside the
            // transaction so two concurrent accepts serialize: the loser sees the
            // updated status instead of converting a second order.
            $quotation = Quotation::query()->whereKey($quotation->getKey())->lockForUpdate()->firstOrFail();

            if ($quotation->status !== QuotationStatus::PricedByAdmin) {
                throw new BusinessException('لا يمكن قبول عرض السعر إلا بعد تسعير الإدارة له.');
            }

            if ($quotation->valid_until !== null && $quotation->valid_until->lt(now()->startOfDay())) {
                $quotation->update(['status' => QuotationStatus::Expired]);

                throw new BusinessException('انتهت صلاحية عرض السعر هذا.');
            }

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
            QuotationAccepted::dispatch($quotation);

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
        QuotationExpired::dispatch($quotation);

        return $quotation->load('items.variant.product');
    }

    /**
     * Move a priced quotation's validity forward.
     *
     * @throws BusinessException If the quotation is not priced or the date is in the past.
     */
    public function extendValidity(Quotation $quotation, string $validUntil): Quotation
    {
        if ($quotation->status !== QuotationStatus::PricedByAdmin) {
            throw new BusinessException('لا يمكن تمديد الصلاحية إلا لعرض مسعّر.');
        }

        $date = Carbon::parse($validUntil)->startOfDay();
        if ($date->lt(now()->startOfDay())) {
            throw new BusinessException('تاريخ الصلاحية لازم يكون النهارده أو بعد كده.');
        }

        $quotation->update(['valid_until' => $date->toDateString()]);

        return $quotation->fresh(['items.variant.product']);
    }

    /**
     * Restores a priced quotation to the snapshot taken before an AI price action.
     *
     * @param  array{valid_until: ?string, discount_amount: string, tax_amount: string, items: list<array{id: int, offered_unit_price: string}>}  $snapshot
     *
     * @throws BusinessException If the trader already accepted the quotation.
     */
    public function restorePricedSnapshot(Quotation $quotation, array $snapshot): Quotation
    {
        if ($quotation->status !== QuotationStatus::PricedByAdmin) {
            throw new BusinessException('التراجع متاح فقط لو العرض لسه مسعّر والتاجر ماقبلهوش.');
        }

        return DB::transaction(function () use ($quotation, $snapshot): Quotation {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if ($quotation->status !== QuotationStatus::PricedByAdmin) {
                throw new BusinessException('التراجع متاح فقط لو العرض لسه مسعّر والتاجر ماقبلهوش.');
            }
            foreach ($snapshot['items'] as $row) {
                $item = $quotation->items()->whereKey($row['id'])->first();
                if ($item === null) {
                    throw new BusinessException('بند التسعير لم يعد موجودًا.');
                }
                $item->update([
                    'offered_unit_price' => $row['offered_unit_price'],
                    'line_total' => round((float) $row['offered_unit_price'] * (float) $item->qty, 2),
                ]);
            }
            $quotation->update([
                'status' => QuotationStatus::Requested,
                'valid_until' => $snapshot['valid_until'],
                'discount_amount' => $snapshot['discount_amount'],
                'tax_amount' => $snapshot['tax_amount'],
                'priced_at' => null,
                'priced_by' => null,
            ]);
            $this->recalculate($quotation);

            return $quotation->fresh(['items']);
        });
    }

    /**
     * Credit and stock problems that would otherwise appear only when the trader accepts.
     *
     * @return list<string>
     */
    public function acceptanceWarnings(Quotation $quotation): array
    {
        $quotation->loadMissing(['customer', 'items.variant']);
        $warnings = [];
        $customer = $quotation->customer;
        if ($customer !== null) {
            $outstanding = app(WholesalePaymentNoticeService::class)->outstandingBalance($customer);
            $over = round($outstanding + (float) $quotation->grand_total - (float) $customer->credit_limit, 2);
            if ($over > 0) {
                $warnings[] = 'العرض ده لو اتقبل هيعدّي حد الائتمان بـ '.number_format($over, 2, '.', ',').'. التاجر هيشوف خطأ وقت القبول.';
            }
        }

        $short = false;
        foreach ($quotation->items as $item) {
            $available = (float) StockLevel::query()
                ->where('product_variant_id', $item->product_variant_id)
                ->sum(DB::raw('COALESCE(on_hand, 0) - COALESCE(reserved, 0) - COALESCE(damaged, 0)'));
            if ($available + 0.00001 < (float) $item->qty) {
                $short = true;
                $sku = $item->variant?->sku ?? '#'.$item->product_variant_id;
                $warnings[] = "الصنف {$sku} متاح منه ".number_format($available, 2, '.', '').' والمطلوب '.number_format((float) $item->qty, 2, '.', '').'. القبول هيفشل لو مفيش توريد.';
            }
        }
        if ($short) {
            $warnings[] = 'لو المخزون مش هيتعوّض قبل القبول، قصّر مدة صلاحية العرض.';
        }

        return $warnings;
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
