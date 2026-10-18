<?php

declare(strict_types=1);

namespace App\Services;

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
        $invoice = DB::transaction(function () use ($data): SupplierInvoice {
            $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);
            $poId = $this->assertOrderBelongsToSupplier($data['purchase_order_id'] ?? null, $supplier);

            $subtotal = round((float) $data['subtotal'], 2);
            $tax = round((float) ($data['tax_amount'] ?? 0), 2);
            $issueDate = Carbon::parse($data['issue_date']);
            // الاستحقاق الافتراضي: تاريخ الإصدار + أجل سداد المورد
            $dueDate = ! empty($data['due_date'])
                ? Carbon::parse($data['due_date'])
                : $issueDate->copy()->addDays((int) $supplier->payment_terms_days);

            return SupplierInvoice::create([
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
        });

        if ($attachment !== null) {
            $invoice->update(['attachment_path' => $attachment->store("supplier_invoices/{$invoice->id}")]);
        }

        return $invoice->fresh();
    }

    /**
     * @param  array{supplier_invoice_no?: string, purchase_order_id?: int|null, issue_date?: string, due_date?: string|null, subtotal?: float|int|string, tax_amount?: float|int|string|null}  $data
     */
    public function updateInvoice(SupplierInvoice $invoice, array $data, ?UploadedFile $attachment = null): SupplierInvoice
    {
        $invoice = DB::transaction(function () use ($invoice, $data): SupplierInvoice {
            $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== SupplierInvoiceStatus::Draft) {
                throw new BusinessException('لا يمكن تعديل فاتورة مورد إلا وهي مسودة.');
            }
            if (array_key_exists('purchase_order_id', $data)) {
                $data['purchase_order_id'] = $this->assertOrderBelongsToSupplier(
                    $data['purchase_order_id'],
                    $invoice->supplier()->firstOrFail(),
                );
            }
            $invoice->update(array_intersect_key($data, array_flip([
                'supplier_invoice_no', 'purchase_order_id', 'issue_date', 'due_date', 'subtotal', 'tax_amount',
            ])));
            $invoice->update(['total_amount' => round((float) $invoice->subtotal + (float) $invoice->tax_amount, 2)]);

            return $invoice;
        });

        if ($attachment !== null) {
            $invoice->update(['attachment_path' => $attachment->store("supplier_invoices/{$invoice->id}")]);
        }

        return $invoice->fresh();
    }

    /** اعتماد الفاتورة: تُثبت الاستحقاق في رصيد المورد الدائن. */
    public function postInvoice(SupplierInvoice $invoice): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice): SupplierInvoice {
            $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== SupplierInvoiceStatus::Draft) {
                throw new BusinessException('لا يمكن اعتماد فاتورة إلا وهي مسودة.');
            }
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($invoice->supplier_id);
            $supplier->increment('current_balance', (float) $invoice->total_amount);
            $invoice->update(['status' => SupplierInvoiceStatus::Posted]);

            return $invoice->fresh();
        });
    }

    /** إلغاء فاتورة: المسودة بلا أثر؛ والمعتمدة غير المسددة تعكس رصيدها. */
    public function cancelInvoice(SupplierInvoice $invoice): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice): SupplierInvoice {
            $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === SupplierInvoiceStatus::Draft) {
                $invoice->update(['status' => SupplierInvoiceStatus::Cancelled]);

                return $invoice->fresh();
            }
            if ($invoice->status === SupplierInvoiceStatus::Posted && (float) $invoice->paid_amount <= 0) {
                $supplier = Supplier::query()->lockForUpdate()->findOrFail($invoice->supplier_id);
                $supplier->decrement('current_balance', (float) $invoice->total_amount);
                $invoice->update(['status' => SupplierInvoiceStatus::Cancelled]);

                return $invoice->fresh();
            }

            throw new BusinessException('لا يمكن إلغاء فاتورة بدأ سدادها؛ عالِج دفعاتها أولًا.');
        });
    }

    public function deleteInvoice(SupplierInvoice $invoice): void
    {
        if (! in_array($invoice->status, [SupplierInvoiceStatus::Draft, SupplierInvoiceStatus::Cancelled], true)) {
            throw new BusinessException('لا يمكن حذف فاتورة معتمدة أو مسددة؛ ألغِها بدلًا من حذفها.');
        }
        $invoice->delete();
    }

    /**
     * تسجيل دفعة لمورد: تخفض رصيده، وتُسند لفاتورة معتمدة عند ربطها
     * (بسقف المتبقي منها) فتتحدث paid_amount وحالتها.
     *
     * @param  array{supplier_id: int, supplier_invoice_id?: int|null, amount: float|int|string, payment_method: string, reference_no?: string|null, payment_date: string, notes?: string|null}  $data
     */
    public function createPayment(array $data, User $creator): SupplierPayment
    {
        return DB::transaction(function () use ($data, $creator): SupplierPayment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail((int) $data['supplier_id']);
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
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

            $payment = SupplierPayment::create([
                'payment_number' => $this->settings->generateNextNumber('supplier_payment'),
                'supplier_id' => $supplier->id,
                'supplier_invoice_id' => $invoice?->id,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference_no' => $data['reference_no'] ?? null,
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

    /** حذف دفعة يعكس أثرها كاملًا: رصيد المورد ومدفوعات فاتورتها. */
    public function deletePayment(SupplierPayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $payment = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($payment->supplier_id);
            $supplier->increment('current_balance', (float) $payment->amount);

            if ($payment->supplier_invoice_id !== null) {
                $invoice = SupplierInvoice::query()->lockForUpdate()->find($payment->supplier_invoice_id);
                if ($invoice !== null && $invoice->status !== SupplierInvoiceStatus::Cancelled) {
                    $invoice->decrement('paid_amount', (float) $payment->amount);
                    $this->syncInvoicePaidStatus($invoice->fresh());
                }
            }
            $payment->delete();
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

    private function assertOrderBelongsToSupplier(mixed $poId, Supplier $supplier): ?int
    {
        if ($poId === null || $poId === '') {
            return null;
        }
        $po = PurchaseOrder::query()->find((int) $poId);
        if ($po === null || (int) $po->supplier_id !== (int) $supplier->id) {
            throw new BusinessException('أمر الشراء المرتبط لا يخص هذا المورد.');
        }

        return $po->id;
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
        $from = $fromDate !== null ? Carbon::parse($fromDate)->toDateString() : $supplier->created_at->toDateString();
        $to = $toDate !== null ? Carbon::parse($toDate)->toDateString() : now()->toDateString();
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
        $running = round((float) $supplier->opening_balance + $creditBefore - $debitBefore, 2);
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

        $entries = [];
        $totalCredit = 0.0;
        $totalDebit = 0.0;
        foreach ($invoices->concat($payments)->sortBy(fn (array $e): array => $e['sort'])->values() as $entry) {
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
