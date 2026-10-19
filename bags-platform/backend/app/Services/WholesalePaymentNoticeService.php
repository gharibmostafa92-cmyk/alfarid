<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryType;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Treasury;
use App\Models\WholesalePaymentNotice;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * إشعارات سداد الجملة (المرحلة 12.1): يرفع التاجر إشعار تحويل/شيك/إيداع،
 * وعند اعتماد الإدارة يُخصم المبلغ من مديونيته الآجلة وتُسجَّل حركة قبض
 * في الخزينة عبر TreasuryService.
 *
 * المديونية الآجلة معرّفة حسابيًا بلا عمود رصيد منفصل:
 *   مجموع طلبات الآجل (credit) غير المسددة وغير الملغاة
 *   − مجموع الإشعارات المعتمدة (الحرّة، أو المرتبطة بطلب ما زال غير مسدد).
 * فإذا غطّت الإشعارات المعتمدة طلبًا مرتبطًا بالكامل تحوّل إلى «paid»
 * وخرج هو وإشعاراته من المعادلة معًا حتى لا تُخصم مرتين.
 */
class WholesalePaymentNoticeService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly TreasuryService $treasury,
    ) {}

    public function create(Customer $customer, array $data, UploadedFile $receipt): WholesalePaymentNotice
    {
        return PrivateAttachments::transaction(function (PrivateAttachments $files) use ($customer, $data, $receipt): WholesalePaymentNotice {
            $order = null;

            if (! empty($data['order_id'])) {
                $order = $this->assertCreditableOrder($customer, (int) $data['order_id']);
                $this->assertOrderCapacity($order, (float) $data['amount']);
            }

            $path = $files->store($receipt, "wholesale_payment_notices/{$customer->id}");

            return WholesalePaymentNotice::create([
                'notice_number' => $this->settings->generateNextNumber('payment_notice'),
                'customer_id' => $customer->id,
                'order_id' => $order?->id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'bank_name' => $data['bank_name'],
                'reference_number' => $data['reference_number'],
                'transfer_date' => $data['transfer_date'],
                'receipt_file_path' => $path,
                'status' => WholesalePaymentNoticeStatus::PendingReview,
            ]);
        });
    }

    /** المديونية الآجلة الحالية للتاجر (انظر تعريف المعادلة في رأس الكلاس). */
    public function outstandingBalance(Customer $customer): float
    {
        $openOrders = Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value);

        $debt = (float) (clone $openOrders)->sum('grand_total');
        $openOrderIds = (clone $openOrders)->pluck('id');

        $credits = (float) WholesalePaymentNotice::query()
            ->where('customer_id', $customer->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->where(function ($query) use ($openOrderIds): void {
                $query->whereNull('order_id')->orWhereIn('order_id', $openOrderIds);
            })
            ->sum('amount');

        return round(max(0.0, $debt - $credits), 2);
    }

    /**
     * كشف حساب التاجر (المرحلة 12.2): المشتريات الآجلة مدينة (Debit)،
     * والسدادات المعتمدة ومرتجعاته المستردة دائنة (Credit)، برصيد تراكمي
     * يبدأ من رصيد افتتاحي محسوب من كل ما قبل الفترة المطلوبة.
     *
     * @return array{opening_balance: float, closing_balance: float, entries: list<array<string, mixed>>}
     */
    public function statement(Customer $customer, ?string $from = null, ?string $to = null): array
    {
        $entries = collect();

        Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->get(['id', 'order_number', 'grand_total', 'created_at'])
            ->each(fn (Order $order) => $entries->push([
                'date' => $order->created_at->copy(),
                'type' => 'debit',
                'description' => "طلب آجل {$order->order_number}",
                'reference' => $order->order_number,
                'amount' => (float) $order->grand_total,
            ]));

        WholesalePaymentNotice::query()
            ->where('customer_id', $customer->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->get(['id', 'notice_number', 'payment_method', 'amount', 'updated_at'])
            ->each(fn (WholesalePaymentNotice $notice) => $entries->push([
                'date' => $notice->updated_at->copy(),
                'type' => 'credit',
                'description' => "سداد {$notice->notice_number} ({$notice->payment_method->value})",
                'reference' => $notice->notice_number,
                'amount' => (float) $notice->amount,
            ]));

        ReturnRequest::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'refunded')
            ->whereNotNull('refunded_at')
            ->get(['id', 'return_number', 'total_refund_amount', 'refunded_at'])
            ->each(fn (ReturnRequest $return) => $entries->push([
                'date' => $return->refunded_at->copy(),
                'type' => 'credit',
                'description' => "مرتجع مسترد {$return->return_number}",
                'reference' => $return->return_number,
                'amount' => (float) $return->total_refund_amount,
            ]));

        // المدين قبل الدائن في اليوم نفسه (اصطلاح كشف حساب المورد)
        $sorted = $entries->sortBy([
            fn (array $a, array $b): int => $a['date']->startOfDay() <=> $b['date']->startOfDay(),
            fn (array $a, array $b): int => ($a['type'] === 'debit' ? 0 : 1) <=> ($b['type'] === 'debit' ? 0 : 1),
        ])->values();

        $fromDate = $from !== null ? Carbon::parse($from)->startOfDay() : null;
        $toDate = $to !== null ? Carbon::parse($to)->endOfDay() : null;

        $opening = 0.0;
        $running = 0.0;
        $result = [];
        foreach ($sorted as $entry) {
            $delta = $entry['type'] === 'debit' ? $entry['amount'] : -$entry['amount'];

            if ($fromDate !== null && $entry['date']->lt($fromDate)) {
                $opening += $delta;
                $running = $opening;

                continue;
            }
            if ($toDate !== null && $entry['date']->gt($toDate)) {
                continue;
            }

            $running += $delta;
            $result[] = [
                'date' => $entry['date']->toDateString(),
                'type' => $entry['type'],
                'description' => $entry['description'],
                'reference' => $entry['reference'],
                'amount' => number_format($entry['amount'], 2, '.', ''),
                'running_balance' => number_format($running, 2, '.', ''),
            ];
        }

        return [
            'opening_balance' => round($opening, 2),
            'closing_balance' => round($running, 2),
            'entries' => $result,
        ];
    }

    public function approve(WholesalePaymentNotice $notice, int $adminId, ?int $treasuryId = null, ?string $adminNotes = null): WholesalePaymentNotice
    {
        return DB::transaction(function () use ($notice, $adminId, $treasuryId, $adminNotes): WholesalePaymentNotice {
            $notice = WholesalePaymentNotice::query()->lockForUpdate()->findOrFail($notice->id);
            $customer = Customer::query()->lockForUpdate()->findOrFail($notice->customer_id);

            if ($notice->status !== WholesalePaymentNoticeStatus::PendingReview) {
                throw new BusinessException('إشعار السداد هذا تمت مراجعته من قبل.');
            }

            if ((float) $notice->amount > $this->outstandingBalance($customer)) {
                throw new BusinessException('مبلغ الإشعار أكبر من المديونية الآجلة الحالية للتاجر.');
            }

            $treasury = $this->resolveTreasury($treasuryId);

            $transaction = $this->treasury->record([
                'treasury_id' => $treasury->id,
                'type' => TreasuryTransactionType::CustomerReceipt,
                'amount' => (float) $notice->amount,
                'reference_type' => 'wholesale_payment_notice',
                'reference_id' => $notice->id,
                'created_by' => $adminId,
                'notes' => "اعتماد إشعار سداد جملة {$notice->notice_number}",
            ]);

            $notice->update([
                'status' => WholesalePaymentNoticeStatus::Approved,
                'reviewed_by' => $adminId,
                'admin_notes' => $adminNotes ?? $notice->admin_notes,
                'treasury_id' => $treasury->id,
                'treasury_transaction_id' => $transaction->id,
            ]);

            if ($notice->order_id !== null) {
                $this->settleOrderIfCovered((int) $notice->order_id);
            }

            return $notice->load(['customer', 'order', 'treasury', 'treasuryTransaction']);
        });
    }

    public function reject(WholesalePaymentNotice $notice, int $adminId, ?string $adminNotes = null): WholesalePaymentNotice
    {
        return DB::transaction(function () use ($notice, $adminId, $adminNotes): WholesalePaymentNotice {
            $notice = WholesalePaymentNotice::query()->lockForUpdate()->findOrFail($notice->id);

            if ($notice->status !== WholesalePaymentNoticeStatus::PendingReview) {
                throw new BusinessException('إشعار السداد هذا تمت مراجعته من قبل.');
            }

            $notice->update([
                'status' => WholesalePaymentNoticeStatus::Rejected,
                'reviewed_by' => $adminId,
                'admin_notes' => $adminNotes ?? $notice->admin_notes,
            ]);

            return $notice->load(['customer', 'order']);
        });
    }

    private function assertCreditableOrder(Customer $customer, int $orderId): Order
    {
        $order = Order::query()->where('customer_id', $customer->id)->find($orderId);

        if ($order === null) {
            throw new BusinessException('الطلب المرتبط غير موجود أو لا يخص هذا التاجر.', [], 404);
        }

        if ($order->payment_method !== 'credit' || $order->payment_status !== 'unpaid'
            || $order->status === OrderStatus::Cancelled) {
            throw new BusinessException('الطلب المرتبط ليس طلب آجل غير مسدد.');
        }

        return $order;
    }

    /** مجموع المعتمد + قيد المراجعة على الطلب نفسه لا يتجاوز إجماليه. */
    private function assertOrderCapacity(Order $order, float $newAmount): void
    {
        $committed = (float) WholesalePaymentNotice::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [
                WholesalePaymentNoticeStatus::PendingReview->value,
                WholesalePaymentNoticeStatus::Approved->value,
            ])
            ->sum('amount');

        if ($committed + $newAmount > (float) $order->grand_total) {
            throw new BusinessException('مبلغ الإشعار يتجاوز المتبقي غير المسدد من هذا الطلب.');
        }
    }

    private function settleOrderIfCovered(int $orderId): void
    {
        $order = Order::query()->lockForUpdate()->find($orderId);

        if ($order === null || $order->payment_status !== 'unpaid') {
            return;
        }

        $approved = (float) WholesalePaymentNotice::query()
            ->where('order_id', $order->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->sum('amount');

        if ($approved >= (float) $order->grand_total) {
            $order->update(['payment_status' => 'paid']);
        }
    }

    private function resolveTreasury(?int $treasuryId): Treasury
    {
        if ($treasuryId !== null) {
            return Treasury::query()->findOrFail($treasuryId);
        }

        $treasury = Treasury::query()->where('is_active', true)
            ->where('type', TreasuryType::BankAccount->value)
            ->orderBy('id')
            ->first()
            ?? Treasury::query()->where('is_active', true)
                ->where('type', TreasuryType::MainSafe->value)
                ->orderBy('id')
                ->first();

        if ($treasury === null) {
            throw new BusinessException('لا توجد خزينة بنكية أو رئيسية نشطة لتسجيل القبض.');
        }

        return $treasury;
    }
}
