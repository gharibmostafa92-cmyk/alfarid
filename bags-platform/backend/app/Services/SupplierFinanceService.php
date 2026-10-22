<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Exceptions\BusinessException;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * مالية الموردين — دفتر المورد الدائن:
 * - اعتماد فاتورة (post) يزيد suppliers.current_balance بقيمة total_amount.
 * - دفعة تنقص الرصيد وتحدّث paid_amount وحالة فاتورتها المرتبطة.
 * - حذف دفعة/إلغاء فاتورة معتمدة يعكس الأثر بالكامل.
 * كل حركة تحت قفل صف المورد حتى لا تتسابق فاتورة ودفعة على نفس الرصيد.
 */
class SupplierFinanceService
{
    public function __construct(protected SettingService $settings) {}

    /**
     * @param  array{supplier_id: int, purchase_order_id?: int|null, supplier_invoice_no: string, issue_date: string, due_date?: string|null, subtotal: float|int|string, tax_amount?: float|int|string|null}  $data
     */
    public function createInvoice(array $data, ?UploadedFile $attachment = null): SupplierInvoice
    {
        $invoice = PrivateAttachments::transaction(function (PrivateAttachments $files) use ($data, $attachment): SupplierInvoice {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail((int) $data['supplier_id']);
            if (! $supplier->is_active) {
                throw new BusinessException('المورد غير نشط.');
            }
            $this->assertInvoiceNumberAvailable($supplier->id, $data['supplier_invoice_no']);
            $poId = $this->assertOrderBelongsToSupplier($data['purchase_order_id'] ?? null, $supplier);
            $subtotal = round((float) $data['subtotal'], 2);
            $tax = round((float) ($data['tax_amount'] ?? 0), 2);
            $this->assertInvoiceDoesNotExceedOrder($poId, round($subtotal + $tax, 2));
            $issueDate = Carbon::parse($data['issue_date']);
            // الاستحقاق الافتراضي: تاريخ الإصدار + أجل سداد المورد
            $dueDate = ! empty($data['due_date'])
                ? Carbon::parse($data['due_date'])
                : $issueDate->copy()->addDays((int) $supplier->payment_terms_days);

            if ($dueDate->lt($issueDate)) {
                throw new BusinessException('تاريخ الاستحقاق يجب ألا يسبق تاريخ الإصدار.');
            }
            $invoice = SupplierInvoice::create([
                'invoice_number' => $this->settings->generateNextNumber('supplier_invoice'),
                'supplier_invoice_no' => $data['supplier_invoice_no'],
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $poId,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total_amount' => round($subtotal + $tax, 2),
                'paid_amount' => 0,
                'status' => SupplierInvoiceStatus::Draft,
            ]);
            if ($attachment !== null) {
                $invoice->update(['attachment_path' => $files->store($attachment, "supplier_invoices/{$invoice->id}")]);
            }

            return $invoice;
        });

        return $invoice->fresh();
    }

    /**
     * @param  array{supplier_invoice_no?: string, purchase_order_id?: int|null, issue_date?: string, due_date?: string|null, subtotal?: float|int|string, tax_amount?: float|int|string|null}  $data
     */
    public function updateInvoice(SupplierInvoice $invoice, array $data, ?UploadedFile $attachment = null): SupplierInvoice
    {
        $invoice = PrivateAttachments::transaction(function (PrivateAttachments $files) use ($invoice, $data, $attachment): SupplierInvoice {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($invoice->supplier_id);
            $invoice = SupplierInvoice::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== SupplierInvoiceStatus::Draft) {
                throw new BusinessException('لا يمكن تعديل فاتورة مورد إلا وهي مسودة.');
            }
            if (array_key_exists('purchase_order_id', $data)) {
                $data['purchase_order_id'] = $this->assertOrderBelongsToSupplier(
                    $data['purchase_order_id'],
                    $invoice->supplier()->firstOrFail(),
                );
            }
            $this->assertInvoiceNumberAvailable($supplier->id, $data['supplier_invoice_no'] ?? $invoice->supplier_invoice_no, $invoice->id);
            $issue = Carbon::parse($data['issue_date'] ?? $invoice->issue_date);
            if (array_key_exists('due_date', $data) && $data['due_date'] === null) {
                $data['due_date'] = $issue->copy()->addDays((int) $supplier->payment_terms_days)->toDateString();
            }
            if (Carbon::parse($data['due_date'] ?? $invoice->due_date)->lt($issue)) {
                throw new BusinessException('تاريخ الاستحقاق يجب ألا يسبق تاريخ الإصدار.');
            }
            $updates = array_intersect_key($data, array_flip([
                'supplier_invoice_no', 'purchase_order_id', 'issue_date', 'due_date', 'subtotal', 'tax_amount',
            ]));
            $subtotal = round((float) ($updates['subtotal'] ?? $invoice->subtotal), 2);
            $tax = round((float) ($updates['tax_amount'] ?? $invoice->tax_amount), 2);
            $updates['subtotal'] = $subtotal;
            $updates['tax_amount'] = $tax;
            $updates['total_amount'] = round($subtotal + $tax, 2);
            $poId = array_key_exists('purchase_order_id', $updates) ? $updates['purchase_order_id'] : $invoice->purchase_order_id;
            $this->assertInvoiceDoesNotExceedOrder($poId, $updates['total_amount'], $invoice->id);
            $invoice->update($updates);

            if ($attachment !== null) {
                $oldPath = $invoice->attachment_path;
                $invoice->update(['attachment_path' => $files->store($attachment, "supplier_invoices/{$invoice->id}")]);
                if ($oldPath) {
                    DB::afterCommit(fn () => Storage::disk('r2')->delete($oldPath));
                }
            }

            return $invoice;
        });

        return $invoice->fresh();
    }

    /** اعتماد الفاتورة: تُثبت الاستحقاق في رصيد المورد الدائن. */
    public function postInvoice(SupplierInvoice $invoice): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice): SupplierInvoice {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($invoice->supplier_id);
            $invoice = SupplierInvoice::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== SupplierInvoiceStatus::Draft) {
                throw new BusinessException('لا يمكن اعتماد فاتورة إلا وهي مسودة.');
            }
            $supplier->increment('current_balance', (float) $invoice->total_amount);
            $invoice->update(['status' => SupplierInvoiceStatus::Posted]);

            return $invoice->fresh();
        });
    }

    /** إلغاء فاتورة: المسودة بلا أثر؛ والمعتمدة غير المسددة تعكس رصيدها. */
    public function cancelInvoice(SupplierInvoice $invoice): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice): SupplierInvoice {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($invoice->supplier_id);
            $invoice = SupplierInvoice::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === SupplierInvoiceStatus::Draft) {
                $invoice->update(['status' => SupplierInvoiceStatus::Cancelled]);

                return $invoice->fresh();
            }
            if ($invoice->status === SupplierInvoiceStatus::Posted && (float) $invoice->paid_amount <= 0) {
                $supplier->decrement('current_balance', (float) $invoice->total_amount);
                $invoice->update(['status' => SupplierInvoiceStatus::Cancelled]);

                return $invoice->fresh();
            }

            throw new BusinessException('لا يمكن إلغاء فاتورة بدأ سدادها؛ عالِج دفعاتها أولًا.');
        });
    }

    public function deleteInvoice(SupplierInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($invoice->supplier_id);
            $invoice = SupplierInvoice::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== SupplierInvoiceStatus::Draft) {
                throw new BusinessException('يمكن حذف الفواتير المسودة فقط؛ يحتفظ بالسجلات الأخرى للتدقيق.');
            }
            $path = $invoice->attachment_path;
            $invoice->delete();
            if ($path) {
                DB::afterCommit(fn () => Storage::disk('r2')->delete($path));
            }
        });
    }

    /**
     * تسجيل دفعة لمورد: تخفض رصيده، وتُسند لفاتورة معتمدة عند ربطها
     * (بسقف المتبقي منها) فتتحدث paid_amount وحالتها.
     *
     * @param  array{supplier_id: int, supplier_invoice_id?: int|null, amount: float|int|string, payment_method: string, reference_no?: string|null, payment_date: string, notes?: string|null}  $data
     */
    public function createPayment(array $data, User $creator, ?string $idempotencyKey = null): SupplierPayment
    {
        return app(OperationIdempotencyService::class)->run('supplier_payment', $creator, $idempotencyKey, $data,
            fn () => $this->executePayment($data, $creator, hash('sha256', $creator->id.':'.$idempotencyKey)));
    }

    private function executePayment(array $data, User $creator, string $idempotencyKey): SupplierPayment
    {
        return DB::transaction(function () use ($data, $creator, $idempotencyKey): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail((int) $data['supplier_id']);
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0 || $amount > 9999999999.99) {
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
                if (! in_array($invoice->status, [SupplierInvoiceStatus::Posted, SupplierInvoiceStatus::PartiallyPaid], true)) {
                    throw new BusinessException('لا يمكن سداد فاتورة إلا وهي معتمدة أو مسددة جزئيًا.');
                }
                $remaining = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);
                if ($amount > $remaining) {
                    throw new BusinessException("مبلغ الدفعة ({$amount}) أكبر من المتبقي على الفاتورة ({$remaining}).");
                }
            }

            $reference = $this->paymentReference($supplier->id, $data['payment_method'], $data['reference_no'] ?? null);
            $payment = SupplierPayment::create([
                'payment_number' => $this->settings->generateNextNumber('supplier_payment'),
                'idempotency_key' => $idempotencyKey,
                'supplier_id' => $supplier->id,
                'supplier_invoice_id' => $invoice?->id,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference_no' => $reference,
                'payment_date' => $data['payment_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
            ]);

            $supplier->decrement('current_balance', $amount);
            if ($invoice !== null) {
                $invoice->increment('paid_amount', $amount);
                $this->syncInvoicePaidStatus($invoice->fresh());
            }

            return $payment->fresh();
        });
    }

    public function updatePayment(SupplierPayment $payment, array $data): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $data): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($payment->supplier_id);
            $payment = SupplierPayment::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($payment->id);
            if ($payment->voided_at !== null) {
                throw new BusinessException('لا يمكن تعديل دفعة ملغاة.');
            }
            $method = $data['payment_method'] ?? $payment->payment_method->value;
            $reference = array_key_exists('reference_no', $data) ? $data['reference_no'] : $payment->reference_no;
            $data['reference_no'] = $this->paymentReference($supplier->id, $method, $reference, $payment->id);
            $payment->update(array_intersect_key($data, array_flip(['payment_method', 'reference_no', 'notes'])));

            return $payment->fresh();
        });
    }

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

    /** إلغاء دفعة يعكس أثرها كاملًا: رصيد المورد ومدفوعات فاتورتها، ويبقى السجل للتدقيق. */
    public function deletePayment(SupplierPayment $payment, ?User $actor = null, ?string $reason = null): void
    {
        $this->voidPayment($payment, $actor, $reason);
    }

    public function voidPayment(SupplierPayment $payment, ?User $actor = null, ?string $reason = null): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $actor, $reason): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($payment->supplier_id);
            $payment = SupplierPayment::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($payment->id);
            if ($payment->voided_at !== null) {
                throw new BusinessException('هذه الدفعة ملغاة سابقًا.');
            }
            $supplier->increment('current_balance', (float) $payment->amount);

            if ($payment->supplier_invoice_id !== null) {
                $invoice = SupplierInvoice::query()->lockForUpdate()->find($payment->supplier_invoice_id);
                if ($invoice !== null && $invoice->status !== SupplierInvoiceStatus::Cancelled) {
                    $invoice->decrement('paid_amount', (float) $payment->amount);
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

    public function allocatePayment(SupplierPayment $payment, int $invoiceId): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $invoiceId): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($payment->supplier_id);
            $payment = SupplierPayment::query()->where('supplier_id', $supplier->id)->lockForUpdate()->findOrFail($payment->id);
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
            if (! in_array($invoice->status, [SupplierInvoiceStatus::Posted, SupplierInvoiceStatus::PartiallyPaid], true)) {
                throw new BusinessException('لا يمكن تخصيص دفعة إلا لفاتورة معتمدة أو مسددة جزئيًا.');
            }
            $remaining = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);
            if ((float) $payment->amount > $remaining) {
                throw new BusinessException("مبلغ الدفعة ({$payment->amount}) أكبر من المتبقي على الفاتورة ({$remaining}).");
            }
            $payment->update(['supplier_invoice_id' => $invoice->id]);
            $invoice->increment('paid_amount', (float) $payment->amount);
            $this->syncInvoicePaidStatus($invoice->fresh());

            return $payment->fresh();
        });
    }

    /** حالة الفاتورة من مدفوعاتها: صفر ← posted، جزئي ← partially_paid، كامل ← paid. */
    private function syncInvoicePaidStatus(SupplierInvoice $invoice): void
    {
        if ($invoice->status === SupplierInvoiceStatus::Draft
            || $invoice->status === SupplierInvoiceStatus::Cancelled) {
            return;
        }
        $paid = round((float) $invoice->paid_amount, 2);
        $total = round((float) $invoice->total_amount, 2);
        $invoice->update(['status' => match (true) {
            $paid >= $total => SupplierInvoiceStatus::Paid,
            $paid > 0 => SupplierInvoiceStatus::PartiallyPaid,
            default => SupplierInvoiceStatus::Posted,
        }]);
    }

    private function assertInvoiceNumberAvailable(int $supplierId, string $number, ?int $exceptId = null): void
    {
        if (SupplierInvoice::query()->where('supplier_id', $supplierId)->where('status', '!=', SupplierInvoiceStatus::Cancelled)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereRaw('lower(btrim(supplier_invoice_no)) = ?', [mb_strtolower(trim($number))])->exists()) {
            throw new BusinessException('رقم فاتورة المورد مسجل سابقًا.', [], 409);
        }
    }

    private function assertOrderBelongsToSupplier(mixed $poId, Supplier $supplier): ?int
    {
        if ($poId === null || $poId === '') {
            return null;
        }
        $po = PurchaseOrder::query()->find((int) $poId);
        if ($po === null || (int) $po->supplier_id !== (int) $supplier->id) {
            throw new BusinessException('أمر الشراء المرتبط لا يخص هذا المورد.');
        }
        if ($po->status === PurchaseOrderStatus::Cancelled) {
            throw new BusinessException('لا يمكن ربط فاتورة بأمر شراء ملغى.');
        }

        return $po->id;
    }

    private function assertInvoiceDoesNotExceedOrder(?int $poId, float $thisTotal, ?int $exceptInvoiceId = null): void
    {
        if ($poId === null) {
            return;
        }
        $po = PurchaseOrder::query()->find($poId);
        if ($po === null) {
            return;
        }
        $existing = (float) SupplierInvoice::query()
            ->where('purchase_order_id', $poId)
            ->where('status', '!=', SupplierInvoiceStatus::Cancelled)
            ->when($exceptInvoiceId, fn ($q) => $q->where('id', '!=', $exceptInvoiceId))
            ->sum('total_amount');
        if (round($existing + $thisTotal, 2) > round((float) $po->grand_total, 2)) {
            throw new BusinessException('مجموع فواتير أمر الشراء يتجاوز إجمالي الأمر.');
        }
    }

    /**
     * كشف حساب المورد (Ledger) لفترة: رصيد افتتاحي محسوب من كل الحركات
     * قبل الفترة، ثم حركات الفترة بترتيب زمني مع رصيد تراكمي متحرك:
     * الفواتير المعتمدة Credit (+) والدفعات Debit (−).
     *
     * @return array<string, mixed>
     */
    public function statement(Supplier $supplier, ?string $fromDate = null, ?string $toDate = null): array
    {
        $to = $toDate !== null ? Carbon::parse($toDate)->toDateString() : now()->toDateString();
        $from = $fromDate !== null ? Carbon::parse($fromDate)->toDateString() : Carbon::parse($to)->subDays(365)->toDateString();
        if (Carbon::parse($from)->gt(Carbon::parse($to)) || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw new BusinessException('فترة كشف الحساب يجب ألا تتجاوز 366 يومًا وبترتيب زمني صحيح.');
        }
        $countedStatuses = [
            SupplierInvoiceStatus::Posted->value,
            SupplierInvoiceStatus::PartiallyPaid->value,
            SupplierInvoiceStatus::Paid->value,
        ];

        $creditBefore = (float) SupplierInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', $countedStatuses)
            ->where('issue_date', '<', $from)
            ->sum('total_amount');
        $debitBefore = (float) SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->where('payment_date', '<', $from)
            ->sum('amount');
        $voidCreditBefore = (float) SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->whereNotNull('voided_at')
            ->whereDate('voided_at', '<', $from)
            ->sum('amount');
        $running = round((float) $supplier->opening_balance + $creditBefore - $debitBefore + $voidCreditBefore, 2);
        $periodOpening = $running;

        $invoices = SupplierInvoice::query()
            ->with('purchaseOrder:id,po_number')
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', $countedStatuses)
            ->whereBetween('issue_date', [$from, $to])
            ->get()
            ->map(fn (SupplierInvoice $inv): array => [
                'date' => $inv->issue_date->toDateString(),
                'type' => 'invoice',
                'reference' => $inv->invoice_number,
                'description' => 'فاتورة مورد '.$inv->supplier_invoice_no.($inv->purchaseOrder !== null ? ' — أمر شراء '.$inv->purchaseOrder->po_number : ''),
                'credit' => round((float) $inv->total_amount, 2),
                'debit' => 0.0,
                'sort' => [$inv->issue_date->toDateString(), 0, $inv->id],
            ]);
        $payments = SupplierPayment::query()
            ->with('invoice:id,invoice_number')
            ->where('supplier_id', $supplier->id)
            ->whereBetween('payment_date', [$from, $to])
            ->get()
            ->map(fn (SupplierPayment $pay): array => [
                'date' => $pay->payment_date->toDateString(),
                'type' => 'payment',
                'reference' => $pay->payment_number,
                'description' => 'دفعة للمورد'.($pay->invoice !== null ? ' — سداد فاتورة '.$pay->invoice->invoice_number : ''),
                'credit' => 0.0,
                'debit' => round((float) $pay->amount, 2),
                'sort' => [$pay->payment_date->toDateString(), 1, $pay->id],
            ]);
        $voids = SupplierPayment::query()
            ->where('supplier_id', $supplier->id)
            ->whereNotNull('voided_at')
            ->whereDate('voided_at', '>=', $from)
            ->whereDate('voided_at', '<=', $to)
            ->get()
            ->map(fn (SupplierPayment $pay): array => [
                'date' => $pay->voided_at->toDateString(),
                'type' => 'payment_void',
                'reference' => $pay->payment_number,
                'description' => 'إلغاء دفعة '.$pay->payment_number.($pay->void_reason ? ' — '.$pay->void_reason : ''),
                'credit' => round((float) $pay->amount, 2),
                'debit' => 0.0,
                'sort' => [$pay->voided_at->toDateString(), 2, $pay->id],
            ]);

        $entries = [];
        $totalCredit = 0.0;
        $totalDebit = 0.0;
        foreach ($invoices->concat($payments)->concat($voids)->sortBy(fn (array $e): array => $e['sort'])->values() as $entry) {
            $running = round($running + $entry['credit'] - $entry['debit'], 2);
            $totalCredit += $entry['credit'];
            $totalDebit += $entry['debit'];
            unset($entry['sort']);
            $entry['running_balance'] = $running;
            $entries[] = $entry;
        }

        return [
            'supplier' => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'current_balance' => $supplier->current_balance,
            ],
            'from_date' => $from,
            'to_date' => $to,
            'opening_balance' => $periodOpening,
            'entries' => $entries,
            'total_credit' => round($totalCredit, 2),
            'total_debit' => round($totalDebit, 2),
            'closing_balance' => $running,
        ];
    }
}
