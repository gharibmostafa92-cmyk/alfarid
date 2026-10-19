<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

/**
 * عروض أسعار الجملة (المرحلة 12.1): يبني التاجر مسودة بأسعار محسوبة من
 * PricingService، يرسلها، تسعّرها الإدارة (أو تعتمد الأسعار المحسوبة)،
 * ثم يقبلها التاجر أو يرفضها قبل انتهاء صلاحيتها. لا أثر مخزني هنا —
 * التحويل لطلب خطوة لاحقة تنفّذ عبر OrderService.
 */
class QuotationService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly PricingService $pricing,
    ) {}

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
     * تسعير الإدارة: تحديث الأسعار المعروضة لبنود محددة (أو اعتماد المحسوبة
     * كما هي) مع الخصم والضريبة وصلاحية العرض.
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

    public function accept(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::PricedByAdmin) {
            throw new BusinessException('لا يمكن قبول عرض السعر إلا بعد تسعير الإدارة له.');
        }

        if ($quotation->valid_until !== null && $quotation->valid_until->lt(now()->startOfDay())) {
            $quotation->update(['status' => QuotationStatus::Expired]);

            throw new BusinessException('انتهت صلاحية عرض السعر هذا.');
        }

        $quotation->update(['status' => QuotationStatus::Accepted]);

        return $quotation->load('items.variant.product');
    }

    public function reject(Quotation $quotation): Quotation
    {
        if (! in_array($quotation->status, [QuotationStatus::Requested, QuotationStatus::PricedByAdmin], true)) {
            throw new BusinessException('لا يمكن رفض عرض السعر في حالته الحالية.');
        }

        $quotation->update(['status' => QuotationStatus::Rejected]);

        return $quotation->load('items.variant.product');
    }

    public function expire(Quotation $quotation): Quotation
    {
        if (! in_array($quotation->status, [QuotationStatus::Requested, QuotationStatus::PricedByAdmin], true)) {
            throw new BusinessException('لا يمكن إنهاء صلاحية عرض السعر في حالته الحالية.');
        }

        $quotation->update(['status' => QuotationStatus::Expired]);

        return $quotation->load('items.variant.product');
    }

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
