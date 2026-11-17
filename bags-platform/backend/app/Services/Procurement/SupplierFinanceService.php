<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Enums\Procurement\PurchaseOrderStatus;
use App\Enums\Procurement\SupplierInvoiceStatus;
use App\Exceptions\BusinessException;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\TreasuryService;
use App\Services\Shared\OperationIdempotencyService;
use App\Services\Shared\PrivateAttachments;
use App\Services\Shared\SettingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Manages supplier accounts payable and vendor ledger accounting:
 * - Posting a vendor invoice credits suppliers.current_balance by total_amount.
 * - Recording a payment debits current_balance and updates paid_amount and invoice settlement status.
 * - Voiding payments or cancelling posted invoices reverses ledger balances cleanly.
 * All operations acquire pessimistic row locks on the supplier record to serialize concurrent financial entries.
 */
class SupplierFinanceService
{
    public function __construct(
        protected SettingService $settings,
        protected BranchAccess $branchAccess,
    ) {}

    /**
     * Create a draft supplier invoice with optional PDF attachment.
     *
     * @param  array{supplier_id: int, purchase_order_id?: int|null, supplier_invoice_no: string, issue_date: string, due_date?: string|null, subtotal: float|int|string, tax_amount?: float|int|string|null}  $data
     *
     * @throws BusinessException
     */
    public function createInvoice(array $data, ?UploadedFile $attachment = null, ?User $actor = null): SupplierInvoice
    {
        $invoice = PrivateAttachments::transaction(function (PrivateAttachments $files) use ($data, $attachment, $actor): SupplierInvoice {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail((int) $data['supplier_id']);
            if (! $supplier->is_active) {
                throw new BusinessException('المورد غير نشط.');
            }
            $this->assertInvoiceNumberAvailable($supplier->id, $data['supplier_invoice_no']);
            $poId = $this->assertOrderBelongsToSupplier($data['purchase_order_id'] ?? null, $supplier, $actor);
            $subtotal = BigDecimal::of((string) $data['subtotal'])->toScale(2, RoundingMode::HalfUp);
            $tax = BigDecimal::of((string) ($data['tax_amount'] ?? '0'))->toScale(2, RoundingMode::HalfUp);
            $total = $subtotal->plus($tax)->toScale(2, RoundingMode::HalfUp);
            if ($total->compareTo('0') <= 0) {
                throw new BusinessException('إجمالي الفاتورة يجب أن يكون أكبر من صفر.');
            }
            $this->assertInvoiceDoesNotExceedOrder($poId, (string) $total);
            [$issueDate, $dueDate] = $this->resolveInvoiceDates(
                $data['issue_date'],
                $data['due_date'] ?? null,
                $supplier
            );
            $invoice = SupplierInvoice::create([
                'invoice_number' => $this->settings->generateNextNumber('supplier_invoice'),
                'supplier_invoice_no' => $data['supplier_invoice_no'],
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $poId,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'subtotal' => (string) $subtotal,
                'tax_amount' => (string) $tax,
                'total_amount' => (string) $total,
                'paid_amount' => 0,
                'status' => SupplierInvoiceStatus::Draft,
            ]);
            if ($attachment !== null) {
                $invoice->update(['attachment_path' => $files->store($attachment, "supplier_invoices/{$invoice->id}")]);
            }

            return $invoice;
        }, [[$attachment, 'supplier_invoices/pending']]);

        return $invoice->fresh();
    }

    /**
     * Update an unposted draft supplier invoice.
     *
     * @param  array{supplier_invoice_no?: string, purchase_order_id?: int|null, issue_date?: string, due_date?: string|null, subtotal?: float|int|string, tax_amount?: float|int|string|null}  $data
     *
     * @throws BusinessException
     */
    public function updateInvoice(SupplierInvoice $invoice, array $data, ?UploadedFile $attachment = null, ?User $actor = null): SupplierInvoice
    {
        $invoice = PrivateAttachments::transaction(function (PrivateAttachments $files) use ($invoice, $data, $attachment, $actor): SupplierInvoice {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($invoice->supplier_id);
            $invoice = SupplierInvoice::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($invoice->id);
            $this->assertInvoiceBranchAccess($actor, $invoice);
            if (! $supplier->is_active) {
                throw new BusinessException('لا يمكن تعديل فاتورة لمورد غير نشط.');
            }
            if ($invoice->status !== SupplierInvoiceStatus::Draft) {
                throw new BusinessException('لا يمكن تعديل فاتورة إلا وهي مسودة.');
            }
            if (array_key_exists('purchase_order_id', $data)) {
                $data['purchase_order_id'] = $this->assertOrderBelongsToSupplier(
                    $data['purchase_order_id'],
                    $supplier,
                    $actor,
                );
            }
            $this->assertInvoiceNumberAvailable($supplier->id, $data['supplier_invoice_no'] ?? $invoice->supplier_invoice_no, $invoice->id);
            [$issue, $due] = $this->resolveInvoiceDates(
                $data['issue_date'] ?? $invoice->issue_date,
                array_key_exists('due_date', $data) ? $data['due_date'] : $invoice->due_date,
                $supplier
            );
            if (array_key_exists('due_date', $data) && empty($data['due_date'])) {
                $data['due_date'] = $due->toDateString();
            }
            $updates = array_intersect_key($data, array_flip([
                'supplier_invoice_no', 'purchase_order_id', 'issue_date', 'due_date', 'subtotal', 'tax_amount',
            ]));
            $subtotal = BigDecimal::of((string) ($updates['subtotal'] ?? $invoice->subtotal))->toScale(2, RoundingMode::HalfUp);
            $tax = BigDecimal::of((string) ($updates['tax_amount'] ?? $invoice->tax_amount))->toScale(2, RoundingMode::HalfUp);
            $updates['subtotal'] = (string) $subtotal;
            $updates['tax_amount'] = (string) $tax;
            $updates['total_amount'] = (string) $subtotal->plus($tax)->toScale(2, RoundingMode::HalfUp);
            if (BigDecimal::of($updates['total_amount'])->compareTo('0') <= 0) {
                throw new BusinessException('إجمالي الفاتورة يجب أن يكون أكبر من صفر.');
            }
            $poId = array_key_exists('purchase_order_id', $updates) ? $updates['purchase_order_id'] : $invoice->purchase_order_id;
            $this->assertInvoiceDoesNotExceedOrder($poId, $updates['total_amount'], $invoice->id);
            $invoice->update($updates);

            if ($attachment !== null) {
                $oldPath = $invoice->attachment_path;
                $invoice->update(['attachment_path' => $files->store($attachment, "supplier_invoices/{$invoice->id}")]);
                if ($oldPath) {
                    DB::afterCommit(fn () => app(PrivateAttachments::class)->delete($oldPath));
                }
            }

            return $invoice;
        }, [[$attachment, 'supplier_invoices/pending']]);

        return $invoice->fresh();
    }

    /**
     * Post a draft invoice and credit the supplier's accounts payable balance.
     *
     *
     * @throws BusinessException
     */
    public function postInvoice(SupplierInvoice $invoice, ?User $actor = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $actor): SupplierInvoice {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($invoice->supplier_id);
            $invoice = SupplierInvoice::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($invoice->id);
            $this->assertInvoiceBranchAccess($actor, $invoice);
            if ($invoice->status !== SupplierInvoiceStatus::Draft) {
                throw new BusinessException('لا يمكن اعتماد فاتورة إلا وهي مسودة.');
            }
            if (! $supplier->is_active) {
                throw new BusinessException('لا يمكن اعتماد فاتورة لمورد غير نشط.');
            }
            if (BigDecimal::of((string) $invoice->total_amount)->compareTo('0') <= 0) {
                throw new BusinessException('إجمالي الفاتورة يجب أن يكون أكبر من صفر.');
            }
            if ($invoice->purchase_order_id !== null) {
                $this->assertPostedInvoiceFitsReceived((int) $invoice->purchase_order_id, (string) $invoice->total_amount, $invoice->id);
            }
            $supplier->increment('current_balance', (string) $invoice->total_amount);
            $invoice->update(['status' => SupplierInvoiceStatus::Posted]);

            return $invoice->fresh();
        });
    }

    /**
     * Cancel a draft invoice or reverse a posted un-paid invoice from the vendor balance.
     *
     *
     * @throws BusinessException
     */
    public function cancelInvoice(SupplierInvoice $invoice, ?User $actor = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $actor): SupplierInvoice {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($invoice->supplier_id);
            $invoice = SupplierInvoice::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($invoice->id);
            $this->assertInvoiceBranchAccess($actor, $invoice);
            if ($invoice->status === SupplierInvoiceStatus::Draft) {
                $invoice->update(['status' => SupplierInvoiceStatus::Cancelled]);

                return $invoice->fresh();
            }
            if ($invoice->status === SupplierInvoiceStatus::Posted && BigDecimal::of((string) $invoice->paid_amount)->compareTo('0') <= 0) {
                $supplier->decrement('current_balance', (string) $invoice->total_amount);
                $invoice->update(['status' => SupplierInvoiceStatus::Cancelled]);

                return $invoice->fresh();
            }

            throw new BusinessException('لا يمكن إلغاء فاتورة بدأ سدادها؛ عالِج دفعاتها أولًا.');
        });
    }

    /**
     * Delete an unposted draft invoice and remove its stored attachment.
     *
     *
     * @throws BusinessException
     */
    public function deleteInvoice(SupplierInvoice $invoice, ?User $actor = null): void
    {
        DB::transaction(function () use ($invoice, $actor): void {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($invoice->supplier_id);
            $invoice = SupplierInvoice::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($invoice->id);
            $this->assertInvoiceBranchAccess($actor, $invoice);
            if ($invoice->status !== SupplierInvoiceStatus::Draft) {
                throw new BusinessException('يمكن حذف الفواتير المسودة فقط؛ يحتفظ بالسجلات الأخرى للتدقيق.');
            }
            $path = $invoice->attachment_path;
            $invoice->delete();
            if ($path) {
                DB::afterCommit(fn () => app(PrivateAttachments::class)->delete($path));
            }
        });
    }

    /**
     * Record a payment to a supplier, decrementing accounts payable balance and settling linked invoice.
     *
     * @param  array{supplier_id: int, supplier_invoice_id?: int|null, amount: float|int|string, payment_method: string, reference_no?: string|null, payment_date: string, notes?: string|null}  $data
     *
     * @throws BusinessException
     */
    public function createPayment(array $data, User $creator, ?string $idempotencyKey = null): SupplierPayment
    {
        // The key is optional for legacy clients. Do not persist a hash of the
        // empty key: every keyless payment by the same actor would collide.
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return $this->executePayment($data, $creator, null);
        }

        return app(OperationIdempotencyService::class)->run('supplier_payment', $creator, $idempotencyKey, $data,
            fn () => $this->executePayment($data, $creator, hash('sha256', $creator->id.':'.$idempotencyKey)));
    }

    private function executePayment(array $data, User $creator, ?string $idempotencyKey): SupplierPayment
    {
        return DB::transaction(function () use ($data, $creator, $idempotencyKey): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail((int) $data['supplier_id']);
            if (! $supplier->is_active) {
                throw new BusinessException('لا يمكن تسجيل دفعة لمورد غير نشط.');
            }
            if (empty($data['treasury_id'])) {
                throw new BusinessException('يجب تحديد خزينة لصرف دفعة المورد.');
            }
            $treasury = Treasury::query()->lockForUpdate()->findOrFail((int) $data['treasury_id']);
            if (! $treasury->is_active) {
                throw new BusinessException('الخزينة المحددة غير نشطة.');
            }
            app(BranchAccess::class)->assertTreasury($creator->id, $treasury);
            $expectedType = $data['payment_method'] === 'cash' ? TreasuryType::MainSafe : TreasuryType::BankAccount;
            if ($treasury->type !== $expectedType) {
                throw new BusinessException('اختر خزينة نقدية للدفع النقدي أو حسابًا بنكيًا للتحويل والشيك.');
            }
            $amount = BigDecimal::of((string) $data['amount'])->toScale(2, RoundingMode::HalfUp);
            if ($amount->compareTo('0') <= 0 || $amount->compareTo('999999999999999999.99') > 0) {
                throw new BusinessException('مبلغ الدفعة يجب أن يكون أكبر من صفر.');
            }

            $invoice = null;
            if (! empty($data['supplier_invoice_id'])) {
                $invoice = SupplierInvoice::query()->lockForUpdate()
                    ->where('supplier_id', $supplier->id)
                    ->find((int) $data['supplier_invoice_id']);
                if ($invoice === null) {
                    throw new BusinessException('الفاتورة المرتبطة غير موجودة لهذا المورد.');
                }
                $this->assertInvoiceBranchAccess($creator, $invoice);
                if (! in_array($invoice->status, [SupplierInvoiceStatus::Posted, SupplierInvoiceStatus::PartiallyPaid], true)) {
                    throw new BusinessException('لا يمكن سداد فاتورة إلا وهي معتمدة أو مسددة جزئيًا.');
                }
                $remaining = BigDecimal::of((string) $invoice->total_amount)->minus((string) $invoice->paid_amount)->toScale(2);
                if ($amount->compareTo($remaining) > 0) {
                    throw new BusinessException("مبلغ الدفعة ({$amount}) أكبر من المتبقي على الفاتورة ({$remaining}).");
                }
            }

            $reference = $this->paymentReference($supplier->id, $data['payment_method'], $data['reference_no'] ?? null);
            try {
                $payment = SupplierPayment::create([
                    'payment_number' => $this->settings->generateNextNumber('supplier_payment'),
                    'idempotency_key' => $idempotencyKey,
                    'supplier_id' => $supplier->id,
                    'treasury_id' => $treasury->id,
                    'supplier_invoice_id' => $invoice?->id,
                    'amount' => (string) $amount,
                    'payment_method' => $data['payment_method'],
                    'reference_no' => $reference,
                    'payment_date' => $data['payment_date'],
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $creator->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessException('رقم مرجع الدفعة مستخدم بالفعل.', [], 409);
            }

            app(TreasuryService::class)->record([
                'treasury_id' => $treasury->id,
                'type' => TreasuryTransactionType::SupplierPayment,
                'amount' => (string) $amount->negated(),
                'reference_type' => 'supplier_payment',
                'reference_id' => $payment->id,
                'created_by' => $creator->id,
            ]);

            $supplier->decrement('current_balance', (string) $amount);
            if ($invoice !== null) {
                $invoice->increment('paid_amount', (string) $amount);
                $this->syncInvoicePaidStatus($invoice->fresh());
            }

            return $payment->fresh();
        });
    }

    /**
     * Update payment metadata such as method, reference number, or notes.
     *
     * @param  array{payment_method?: string, reference_no?: string|null, notes?: string|null}  $data
     *
     * @throws BusinessException
     */
    public function updatePayment(SupplierPayment $payment, array $data, ?User $actor = null): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $data, $actor): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($payment->supplier_id);
            $payment = SupplierPayment::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($payment->id);
            $this->assertPaymentBranchAccess($actor, $payment);
            if ($payment->voided_at !== null) {
                throw new BusinessException('لا يمكن تعديل دفعة ملغاة.');
            }
            $method = $data['payment_method'] ?? $payment->payment_method->value;
            if ($method !== $payment->payment_method->value) {
                throw new BusinessException('وسيلة الدفع لا تتغير بعد التسجيل؛ ألغِ الدفعة وسجّلها من جديد.');
            }
            $reference = array_key_exists('reference_no', $data) ? $data['reference_no'] : $payment->reference_no;
            $data['reference_no'] = $this->paymentReference($supplier->id, $method, $reference, $payment->id);
            try {
                $payment->update(array_intersect_key($data, array_flip(['payment_method', 'reference_no', 'notes'])));
            } catch (UniqueConstraintViolationException) {
                throw new BusinessException('رقم مرجع الدفعة مستخدم بالفعل.', [], 409);
            }

            return $payment->fresh();
        });
    }

    /**
     * Validate uniqueness of reference number per supplier for non-cash payment methods.
     */
    private function paymentReference(int $supplierId, string $method, ?string $reference, ?int $exceptId = null): ?string
    {
        $reference = $reference !== null ? trim($reference) : null;
        if ($method !== 'cash' && ($reference === null || $reference === '')) {
            throw new BusinessException('مرجع الدفع مطلوب للدفعات غير النقدية.');
        }
        if ($method !== 'cash' && SupplierPayment::query()->where('supplier_id', $supplierId)
            ->whereNull('voided_at')
            ->where('payment_method', '!=', 'cash')->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereRaw('lower(btrim(reference_no)) = ?', [mb_strtolower($reference)])->exists()) {
            throw new BusinessException('مرجع الدفع مسجل سابقًا لهذا المورد.', [], 409);
        }

        return $reference;
    }

    /**
     * Void a payment to reverse its ledger effects while preserving the audit record.
     */
    public function deletePayment(SupplierPayment $payment, ?User $actor = null, ?string $reason = null): void
    {
        $this->voidPayment($payment, $actor, $reason);
    }

    /**
     * Void a supplier payment, restoring vendor payable balance and deducting paid_amount on linked invoice.
     *
     *
     * @throws BusinessException
     */
    public function voidPayment(SupplierPayment $payment, ?User $actor = null, ?string $reason = null): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $actor, $reason): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($payment->supplier_id);
            $payment = SupplierPayment::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($payment->id);
            if ($payment->voided_at !== null) {
                throw new BusinessException('هذه الدفعة ملغاة سابقًا.');
            }
            $this->assertPaymentBranchAccess($actor, $payment);
            if ($payment->treasury_id !== null) {
                if ($actor === null) {
                    throw new BusinessException('يجب تحديد الموظف الذي يلغي الدفعة.');
                }
                $treasury = Treasury::query()->lockForUpdate()->findOrFail($payment->treasury_id);
                app(BranchAccess::class)->assertTreasury($actor->id, $treasury);
                app(TreasuryService::class)->record([
                    'treasury_id' => $treasury->id,
                    'type' => TreasuryTransactionType::SupplierPaymentReversal,
                    'amount' => (string) $payment->amount,
                    'reference_type' => 'supplier_payment',
                    'reference_id' => $payment->id,
                    'created_by' => $actor->id,
                ]);
            }
            $supplier->increment('current_balance', (string) $payment->amount);

            if ($payment->supplier_invoice_id !== null) {
                $invoice = SupplierInvoice::query()->lockForUpdate()->find($payment->supplier_invoice_id);
                if ($invoice !== null && $invoice->status !== SupplierInvoiceStatus::Cancelled) {
                    $invoice->decrement('paid_amount', (string) $payment->amount);
                    $this->syncInvoicePaidStatus($invoice->fresh());
                }
            }
            $payment->update([
                'voided_at' => now(),
                'voided_by' => $actor?->id,
                'void_reason' => $reason,
            ]);

            return $payment->fresh();
        });
    }

    /**
     * Allocate an unlinked payment toward an existing posted invoice.
     *
     *
     * @throws BusinessException
     */
    public function allocatePayment(SupplierPayment $payment, int $invoiceId, ?User $actor = null): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $invoiceId, $actor): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($payment->supplier_id);
            $payment = SupplierPayment::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($payment->id);
            $this->assertPaymentBranchAccess($actor, $payment);
            if ($payment->voided_at !== null) {
                throw new BusinessException('لا يمكن تخصيص دفعة ملغاة.');
            }
            if ($payment->supplier_invoice_id !== null) {
                throw new BusinessException('هذه الدفعة مخصصة لفاتورة بالفعل.');
            }
            $invoice = SupplierInvoice::query()->lockForUpdate()
                ->where('supplier_id', $supplier->id)
                ->find($invoiceId);
            if ($invoice === null) {
                throw new BusinessException('الفاتورة المرتبطة غير موجودة لهذا المورد.');
            }
            $this->assertInvoiceBranchAccess($actor, $invoice);
            if (! in_array($invoice->status, [SupplierInvoiceStatus::Posted, SupplierInvoiceStatus::PartiallyPaid], true)) {
                throw new BusinessException('لا يمكن تخصيص دفعة إلا لفاتورة معتمدة أو مسددة جزئيًا.');
            }
            $remaining = BigDecimal::of((string) $invoice->total_amount)->minus((string) $invoice->paid_amount)->toScale(2);
            if (BigDecimal::of((string) $payment->amount)->compareTo($remaining) > 0) {
                throw new BusinessException("مبلغ الدفعة ({$payment->amount}) أكبر من المتبقي على الفاتورة ({$remaining}).");
            }
            $payment->update(['supplier_invoice_id' => $invoice->id]);
            $invoice->increment('paid_amount', (string) $payment->amount);
            $this->syncInvoicePaidStatus($invoice->fresh());

            return $payment->fresh();
        });
    }

    /**
     * Update invoice payment status based on cumulative settled amounts (posted, partially_paid, paid).
     */
    private function syncInvoicePaidStatus(SupplierInvoice $invoice): void
    {
        if ($invoice->status === SupplierInvoiceStatus::Draft
            || $invoice->status === SupplierInvoiceStatus::Cancelled) {
            return;
        }
        $paid = BigDecimal::of((string) $invoice->paid_amount);
        $total = BigDecimal::of((string) $invoice->total_amount);
        $invoice->update(['status' => match (true) {
            $paid->compareTo($total) >= 0 => SupplierInvoiceStatus::Paid,
            $paid->compareTo('0') > 0 => SupplierInvoiceStatus::PartiallyPaid,
            default => SupplierInvoiceStatus::Posted,
        }]);
    }

    /**
     * Resolve and validate invoice issue and due dates against supplier credit terms.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveInvoiceDates(mixed $issueRaw, mixed $dueRaw, Supplier $supplier): array
    {
        $issue = Carbon::parse($issueRaw);
        $due = ! empty($dueRaw)
            ? Carbon::parse($dueRaw)
            : $issue->copy()->addDays((int) $supplier->payment_terms_days);

        if ($due->lt($issue)) {
            throw new BusinessException('تاريخ الاستحقاق يجب ألا يسبق تاريخ الإصدار.');
        }

        return [$issue, $due];
    }

    private function assertInvoiceNumberAvailable(int $supplierId, string $number, ?int $exceptId = null): void
    {
        if (SupplierInvoice::query()->where('supplier_id', $supplierId)->where('status', '!=', SupplierInvoiceStatus::Cancelled)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereRaw('lower(btrim(supplier_invoice_no)) = ?', [mb_strtolower(trim($number))])->exists()) {
            throw new BusinessException('رقم فاتورة المورد مسجل سابقًا.', [], 409);
        }
    }

    private function assertOrderBelongsToSupplier(mixed $poId, Supplier $supplier, ?User $actor = null): ?int
    {
        if ($poId === null || $poId === '') {
            $this->assertOrderBranchAccess($actor, null);

            return null;
        }
        // Serialize invoice checks with order edits, cancellation and closeShort.
        $po = PurchaseOrder::query()->lockForUpdate()->find((int) $poId);
        if ($po === null || (int) $po->supplier_id !== (int) $supplier->id) {
            throw new BusinessException('أمر الشراء المرتبط لا يخص هذا المورد.');
        }
        if ($po->status === PurchaseOrderStatus::Cancelled) {
            throw new BusinessException('لا يمكن ربط فاتورة بأمر شراء ملغى.');
        }
        if ($po->status === PurchaseOrderStatus::Draft) {
            throw new BusinessException('لا يمكن ربط فاتورة بأمر شراء لم يُرسل بعد.');
        }

        $this->assertOrderBranchAccess($actor, $po);

        return $po->id;
    }

    private function resolveActor(?User $actor): User
    {
        $actor ??= auth()->user();
        if (! $actor instanceof User) {
            throw new AuthorizationException('يلزم تحديد موظف مخول لتنفيذ عمليات الموردين.');
        }

        return $actor;
    }

    private function assertOrderBranchAccess(?User $actor, ?PurchaseOrder $po): void
    {
        $actor = $this->resolveActor($actor);
        if ($po === null) {
            $this->branchAccess->assertCentralAccess($actor);

            return;
        }
        $this->branchAccess->assertBranch($actor->id, (int) $po->branch_id);
    }

    private function assertInvoiceBranchAccess(?User $actor, SupplierInvoice $invoice): void
    {
        $actor = $this->resolveActor($actor);
        $po = $invoice->purchase_order_id === null
            ? null
            : PurchaseOrder::query()->find($invoice->purchase_order_id);
        $this->assertOrderBranchAccess($actor, $po);
    }

    private function assertPaymentBranchAccess(?User $actor, SupplierPayment $payment): void
    {
        $actor = $this->resolveActor($actor);
        if ($payment->treasury_id === null) {
            $this->branchAccess->assertCentralAccess($actor);
        } else {
            $this->branchAccess->assertTreasury(
                $actor->id,
                Treasury::query()->findOrFail($payment->treasury_id),
            );
        }
        if ($payment->supplier_invoice_id !== null) {
            $invoice = SupplierInvoice::query()->find($payment->supplier_invoice_id);
            if ($invoice !== null) {
                $this->assertInvoiceBranchAccess($actor, $invoice);
            }
        }
    }

    private function assertInvoiceDoesNotExceedOrder(?int $poId, string $thisTotal, ?int $exceptInvoiceId = null): void
    {
        // Draft-time check: allow recording vendor invoices up to the order grand total.
        // The strict 3-way match (received goods ceiling, posted invoices only) runs at post time.
        if ($poId === null) {
            return;
        }
        $po = PurchaseOrder::query()->lockForUpdate()->find($poId);
        if ($po === null) {
            return;
        }
        $existing = (string) SupplierInvoice::query()
            ->where('purchase_order_id', $poId)
            ->where('status', '!=', SupplierInvoiceStatus::Cancelled)
            ->when($exceptInvoiceId, fn ($q) => $q->where('id', '!=', $exceptInvoiceId))
            ->sum('total_amount');
        if (BigDecimal::of($existing)->plus($thisTotal)->compareTo((string) $po->grand_total) > 0) {
            throw new BusinessException('مجموع فواتير أمر الشراء يتجاوز إجمالي الأمر.');
        }
    }

    private function assertPostedInvoiceFitsReceived(?int $poId, string $thisTotal, ?int $exceptInvoiceId = null): void
    {
        if ($poId === null) {
            return;
        }
        $po = PurchaseOrder::query()->lockForUpdate()->find($poId);
        if ($po === null) {
            return;
        }
        $existing = (string) SupplierInvoice::query()
            ->where('purchase_order_id', $poId)
            ->whereIn('status', [SupplierInvoiceStatus::Posted, SupplierInvoiceStatus::PartiallyPaid, SupplierInvoiceStatus::Paid])
            ->when($exceptInvoiceId, fn ($q) => $q->where('id', '!=', $exceptInvoiceId))
            ->sum('total_amount');
        if (BigDecimal::of($existing)->plus($thisTotal)->compareTo((string) $this->invoiceCeiling($po)) > 0) {
            throw new BusinessException('مجموع الفواتير المعتمدة يتجاوز قيمة البضاعة المستلمة فعليًا على أمر الشراء.');
        }
    }

    /** Invoice ceiling is based on received goods, or the stored total when fully received. */
    private function invoiceCeiling(PurchaseOrder $po): BigDecimal
    {
        $po->loadMissing('items');
        $fullyReceived = $po->items->isNotEmpty() && $po->items->every(
            fn ($item): bool => BigDecimal::of((string) $item->received_qty)
                ->compareTo((string) $item->ordered_qty) === 0,
        );
        // A fully received order must match the stored grand total exactly; recalculating
        // from rounded line values can drift by a cent and reject a matching invoice.
        if ($fullyReceived) {
            return BigDecimal::of((string) $po->grand_total)->toScale(2);
        }
        $receivedSubtotalExact = BigDecimal::zero();
        $taxBaseWeights = [];
        foreach ($po->items as $item) {
            $line = BigDecimal::of((string) $item->received_qty)
                ->multipliedBy((string) $item->unit_cost)->toScale(4, RoundingMode::HalfUp);
            $receivedSubtotalExact = $receivedSubtotalExact->plus($line);
            $taxBaseWeights[] = ['line' => $line, 'rate' => BigDecimal::of((string) $item->tax_rate)];
        }
        $poSubtotal = BigDecimal::of((string) $po->subtotal);
        $poDiscount = BigDecimal::of((string) $po->discount_amount);
        $netFactor = $poSubtotal->compareTo('0') > 0
            ? $poSubtotal->minus($poDiscount)->dividedBy($poSubtotal, 12, RoundingMode::HalfUp)
            : BigDecimal::one();

        $receivedNetExact = $receivedSubtotalExact->multipliedBy($netFactor);
        $receivedTaxExact = BigDecimal::zero();
        foreach ($taxBaseWeights as $line) {
            $receivedTaxExact = $receivedTaxExact->plus(
                $line['line']->multipliedBy($netFactor)
                    ->multipliedBy($line['rate'])
                    ->dividedBy('100', 12, RoundingMode::HalfUp)
            );
        }

        return $receivedNetExact->plus($receivedTaxExact)->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Reject closing a purchase order short when posted invoices exceed the value actually received.
     *
     * @throws BusinessException
     */
    public function assertInvoicesFitClosedOrder(PurchaseOrder $po): void
    {
        $ceiling = $this->invoiceCeiling($po);
        $invoiced = BigDecimal::of((string) SupplierInvoice::query()
            ->where('purchase_order_id', $po->id)
            ->whereIn('status', [
                SupplierInvoiceStatus::Posted->value,
                SupplierInvoiceStatus::PartiallyPaid->value,
                SupplierInvoiceStatus::Paid->value,
            ])
            ->sum('total_amount'))->toScale(2);

        if ($invoiced->compareTo($ceiling) > 0) {
            throw new BusinessException(
                "قيمة الفواتير المرتبطة ({$invoiced}) تتجاوز قيمة البضاعة المستلمة ({$ceiling}); عدّل أو ألغِ الفواتير الزائدة قبل إغلاق النقص."
            );
        }
    }

    /**
     * Generate chronological supplier ledger statement with period opening balance and running totals.
     *
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    public function statement(Supplier $supplier, ?string $fromDate = null, ?string $toDate = null, bool $internal = false): array
    {
        // SET TRANSACTION ... must be the first statement of a top-level
        // transaction. Inside an outer transaction (e.g. test refresh
        // transactions or a caller-managed unit of work) it is illegal, so
        // only request the repeatable-read snapshot when we own the
        // outermost transaction; production behavior is unchanged.
        $ownsTransaction = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($supplier, $fromDate, $toDate, $internal, $ownsTransaction): array {
            if ($ownsTransaction && DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            return $this->buildStatementSnapshot($supplier, $fromDate, $toDate, $internal);
        });
    }

    /** Read all ledger rows and the current balance from the same database snapshot. */
    private function buildStatementSnapshot(Supplier $supplier, ?string $fromDate, ?string $toDate, bool $internal): array
    {
        // Snapshot freshness: ensure opening and current balances are read from the exact active snapshot
        $supplier = Supplier::query()->findOrFail($supplier->id);
        $zone = (string) config('app.timezone');
        $today = Carbon::now($zone)->toDateString();
        if ($toDate === null && $fromDate !== null) {
            $candidateTo = Carbon::parse($fromDate, $zone)->addDays(365)->toDateString();
            $to = $candidateTo < $today ? $candidateTo : $today;
        } else {
            $to = $toDate !== null ? Carbon::parse($toDate, $zone)->toDateString() : $today;
        }
        $from = $fromDate !== null ? Carbon::parse($fromDate, $zone)->toDateString() : Carbon::parse($to, $zone)->subDays(365)->toDateString();
        $fromStart = Carbon::parse($from, $zone)->startOfDay();
        $toExclusive = Carbon::parse($to, $zone)->addDay()->startOfDay();
        if (Carbon::parse($from, $zone)->gt(Carbon::parse($to, $zone)) || Carbon::parse($from, $zone)->diffInDays(Carbon::parse($to, $zone)) > 366) {
            throw new BusinessException('فترة كشف الحساب يجب ألا تتجاوز 366 يومًا وبترتيب زمني صحيح.');
        }
        $countedStatuses = [
            SupplierInvoiceStatus::Posted->value,
            SupplierInvoiceStatus::PartiallyPaid->value,
            SupplierInvoiceStatus::Paid->value,
        ];

        $creditBefore = BigDecimal::of((string) SupplierInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', $countedStatuses)
            ->where('issue_date', '<', $from)
            ->sum('total_amount'));
        $debitBefore = BigDecimal::of((string) SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->where('payment_date', '<', $from)
            ->sum('amount'));
        $voidCreditBefore = BigDecimal::of((string) SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->whereNotNull('voided_at')
            ->where('voided_at', '<', $fromStart)
            ->sum('amount'));
        $running = BigDecimal::of((string) $supplier->opening_balance)->plus($creditBefore)->minus($debitBefore)->plus($voidCreditBefore)->toScale(2);
        $periodOpening = $running;

        $invoices = SupplierInvoice::query()
            ->leftJoin('purchase_orders', 'purchase_orders.id', '=', 'supplier_invoices.purchase_order_id')
            ->where('supplier_invoices.supplier_id', $supplier->id)
            ->whereIn('supplier_invoices.status', $countedStatuses)
            ->whereBetween('supplier_invoices.issue_date', [$from, $to])
            ->select([
                'supplier_invoices.id',
                'supplier_invoices.invoice_number',
                'supplier_invoices.supplier_invoice_no',
                'supplier_invoices.issue_date',
                'supplier_invoices.total_amount',
                'purchase_orders.po_number',
            ])
            ->toBase()
            ->get()
            ->map(fn ($inv): array => [
                'date' => Carbon::parse($inv->issue_date)->toDateString(),
                'type' => 'invoice',
                'reference' => (string) $inv->invoice_number,
                'description' => 'فاتورة مورد '.$inv->supplier_invoice_no.($inv->po_number ? ' — أمر شراء '.$inv->po_number : ''),
                'credit' => (string) BigDecimal::of((string) $inv->total_amount)->toScale(2),
                'debit' => '0.00',
                'sort' => [Carbon::parse($inv->issue_date)->toDateString(), 0, (int) $inv->id],
            ]);

        $payments = SupplierPayment::query()
            ->leftJoin('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_payments.supplier_invoice_id')
            ->where('supplier_payments.supplier_id', $supplier->id)
            ->whereBetween('supplier_payments.payment_date', [$from, $to])
            ->select([
                'supplier_payments.id',
                'supplier_payments.payment_number',
                'supplier_payments.amount',
                'supplier_payments.payment_date',
                'supplier_invoices.invoice_number',
            ])
            ->toBase()
            ->get()
            ->map(fn ($pay): array => [
                'date' => Carbon::parse($pay->payment_date)->toDateString(),
                'type' => 'payment',
                'reference' => (string) $pay->payment_number,
                'description' => 'دفعة للمورد'.($pay->invoice_number ? ' — سداد فاتورة '.$pay->invoice_number : ''),
                'credit' => '0.00',
                'debit' => (string) BigDecimal::of((string) $pay->amount)->toScale(2),
                'sort' => [Carbon::parse($pay->payment_date)->toDateString(), 1, (int) $pay->id],
            ]);

        $voids = SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->whereNotNull('voided_at')
            ->where('voided_at', '>=', $fromStart)
            ->where('voided_at', '<', $toExclusive)
            ->select([
                'id',
                'payment_number',
                'amount',
                'voided_at',
                'void_reason',
            ])
            ->toBase()
            ->get()
            ->map(fn ($pay): array => [
                'date' => Carbon::parse($pay->voided_at)->timezone($zone)->toDateString(),
                'type' => 'payment_void',
                'reference' => (string) $pay->payment_number,
                'description' => 'إلغاء دفعة '.$pay->payment_number.($internal && $pay->void_reason ? ' — '.$pay->void_reason : ''),
                'credit' => (string) BigDecimal::of((string) $pay->amount)->toScale(2),
                'debit' => '0.00',
                'sort' => [Carbon::parse($pay->voided_at)->timezone($zone)->toDateString(), 2, (int) $pay->id],
            ]);

        $totalCount = $invoices->count() + $payments->count() + $voids->count();
        if ($totalCount > 5000) {
            throw new BusinessException('عدد حركات كشف الحساب يتجاوز 5000 حركة؛ يرجى تضييق النطاق الزمني.');
        }

        $entries = [];
        $totalCredit = BigDecimal::zero()->toScale(2);
        $totalDebit = BigDecimal::zero()->toScale(2);
        foreach ($invoices->concat($payments)->concat($voids)->sortBy(fn (array $e): array => $e['sort'])->values() as $entry) {
            $running = $running->plus($entry['credit'])->minus($entry['debit'])->toScale(2);
            $totalCredit = $totalCredit->plus($entry['credit'])->toScale(2);
            $totalDebit = $totalDebit->plus($entry['debit'])->toScale(2);
            unset($entry['sort']);
            $entry['running_balance'] = (string) $running;
            $entries[] = $entry;
        }

        return [
            'supplier' => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'current_balance' => (string) $supplier->current_balance,
            ],
            'from_date' => $from,
            'to_date' => $to,
            'opening_balance' => (string) $periodOpening,
            'entries' => $entries,
            'total_credit' => (string) $totalCredit,
            'total_debit' => (string) $totalDebit,
            'closing_balance' => (string) $running,
        ];
    }
}
