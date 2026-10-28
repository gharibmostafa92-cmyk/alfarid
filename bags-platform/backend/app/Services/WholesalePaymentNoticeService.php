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
use App\Models\User;
use App\Models\WholesalePaymentAllocation;
use App\Models\WholesalePaymentNotice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Manages wholesale customer payment notices, credit balance calculation, and order allocations.
 */
class WholesalePaymentNoticeService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly TreasuryService $treasury,
    ) {}

    /**
     * Submits a new payment notice with an uploaded proof receipt.
     *
     * @param Customer $customer Submitting wholesale customer
     * @param array<string, mixed> $data Notice metadata
     * @param UploadedFile $receipt Proof of payment file
     * @return WholesalePaymentNotice Created pending notice
     * @throws BusinessException If linked order is invalid or capacity exceeded
     */
    public function create(Customer $customer, array $data, UploadedFile $receipt): WholesalePaymentNotice
    {
        return PrivateAttachments::transaction(function (PrivateAttachments $files) use ($customer, $data, $receipt): WholesalePaymentNotice {
            $order = null;

            if (! empty($data['order_id'])) {
                // Pessimistic lock inside transaction serializes concurrent submissions against remaining balance.
                $order = $this->assertCreditableOrder($customer, (int) $data['order_id']);
                $this->assertOrderCapacity($order, (string) $data['amount']);
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

    /**
     * Computes the current net debt for a wholesale customer (negative implies credit balance).
     *
     * @param Customer $customer Wholesale customer
     * @return float Current outstanding debt or negative advance credit
     */
    public function outstandingBalance(Customer $customer): float
    {
        return (float) (string) $this->outstandingBalanceDecimal($customer);
    }

    private function outstandingBalanceDecimal(Customer $customer): BigDecimal
    {
        $debt = (string) Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('grand_total');

        // Fully paid orders and their linked notices are excluded to avoid double deduction.
        $linkedOpen = (string) WholesalePaymentNotice::query()
            ->where('customer_id', $customer->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->whereNotNull('order_id')
            ->whereExists(function ($exists): void {
                $exists->selectRaw('1')->from('orders as o')
                    ->whereColumn('o.id', 'wholesale_payment_notices.order_id')
                    ->where('o.payment_status', 'unpaid')
                    ->where('o.status', '!=', OrderStatus::Cancelled->value);
            })
            ->sum('amount');

        // Unallocated free notices and notices linked to cancelled orders act as floating customer credit.
        $free = $this->freePoolTotal($customer);
        $allocated = $this->allocatedTotal($customer);
        $credits = BigDecimal::of($linkedOpen)
            ->plus($free->minus($allocated))
            ->toScale(2, RoundingMode::HalfUp);

        return BigDecimal::of($debt)->minus($credits)->toScale(2, RoundingMode::HalfUp);
    }

    private function freeNoticesQuery(Customer $customer)
    {
        return WholesalePaymentNotice::query()
            ->where('customer_id', $customer->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->where(function ($query): void {
                $query->whereNull('order_id')
                    ->orWhereExists(function ($exists): void {
                        $exists->selectRaw('1')->from('orders as o')
                            ->whereColumn('o.id', 'wholesale_payment_notices.order_id')
                            ->where('o.status', OrderStatus::Cancelled->value);
                    });
            });
    }

    private function freePoolTotal(Customer $customer): BigDecimal
    {
        return BigDecimal::of((string) $this->freeNoticesQuery($customer)->sum('amount'))
            ->toScale(2, RoundingMode::HalfUp);
    }

    private function allocatedTotal(Customer $customer): BigDecimal
    {
        return BigDecimal::of((string) WholesalePaymentAllocation::query()
            ->whereHas('notice', fn ($q) => $q->where('customer_id', $customer->id))
            ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
            ->sum('amount'))->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Generates a chronological statement of account including debits, credits, and opening balance.
     *
     * @param Customer $customer Wholesale customer
     * @param string|null $from Start date (inclusive, YYYY-MM-DD)
     * @param string|null $to End date (inclusive, YYYY-MM-DD)
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

        // Standard accounting convention: debits precede credits on identical dates.
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

    /**
     * Approves a payment notice, recording treasury receipt and allocating against open debt.
     *
     * @param WholesalePaymentNotice $notice Target notice
     * @param int $adminId Approving staff member ID
     * @param int|null $treasuryId Destination treasury ID
     * @param string|null $adminNotes Optional administrative review notes
     * @return WholesalePaymentNotice Updated notice instance
     * @throws BusinessException If notice was reviewed, exceeds debt, or treasury is invalid
     */
    public function approve(WholesalePaymentNotice $notice, int $adminId, ?int $treasuryId = null, ?string $adminNotes = null): WholesalePaymentNotice
    {
        return DB::transaction(function () use ($notice, $adminId, $treasuryId, $adminNotes): WholesalePaymentNotice {
            $notice = WholesalePaymentNotice::query()->lockForUpdate()->findOrFail($notice->id);
            $customer = Customer::query()->lockForUpdate()->findOrFail($notice->customer_id);

            if ($notice->status !== WholesalePaymentNoticeStatus::PendingReview) {
                throw new BusinessException('إشعار السداد هذا تمت مراجعته من قبل.');
            }

            // Decimal comparison avoids floating-point precision boundary errors.
            if (BigDecimal::of((string) $notice->amount)->compareTo($this->outstandingBalanceDecimal($customer)) > 0) {
                throw new BusinessException('مبلغ الإشعار أكبر من المديونية الآجلة الحالية للتاجر.');
            }

            $treasury = $this->resolveTreasury($treasuryId);
            app(BranchAccess::class)->assertTreasury($adminId, $treasury);

            $transaction = $this->treasury->record([
                'treasury_id' => $treasury->id,
                'type' => TreasuryTransactionType::CustomerReceipt,
                'amount' => (string) $notice->amount,
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
                $this->settleOrderIfCovered((int) $notice->order_id, $adminId, $notice->notice_number);
            }
            $this->settleFreeNoticeFifo($customer, $adminId, $notice->notice_number);

            return $notice->load(['customer', 'order', 'treasury', 'treasuryTransaction']);
        });
    }

    /**
     * Rejects a pending wholesale payment notice.
     *
     * @param WholesalePaymentNotice $notice Target notice
     * @param int $adminId Reviewing staff member ID
     * @param string|null $adminNotes Reason for rejection
     * @return WholesalePaymentNotice Updated notice instance
     * @throws BusinessException If notice was already reviewed
     */
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
        // Lock order row to prevent concurrent notices from exceeding total order value.
        $order = Order::query()->where('customer_id', $customer->id)->lockForUpdate()->find($orderId);

        if ($order === null) {
            throw new BusinessException('الطلب المرتبط غير موجود أو لا يخص هذا التاجر.', [], 404);
        }

        if ($order->payment_method !== 'credit' || $order->payment_status !== 'unpaid'
            || $order->status === OrderStatus::Cancelled) {
            throw new BusinessException('الطلب المرتبط ليس طلب آجل غير مسدد.');
        }

        return $order;
    }

    private function assertOrderCapacity(Order $order, string $newAmount): void
    {
        // Include pending reviews so concurrent submissions cannot collectively overcommit the order.
        $committed = (string) WholesalePaymentNotice::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [
                WholesalePaymentNoticeStatus::PendingReview->value,
                WholesalePaymentNoticeStatus::Approved->value,
            ])
            ->sum('amount');

        $total = BigDecimal::of($committed)->plus(BigDecimal::of($newAmount))->toScale(2, RoundingMode::HalfUp);
        if ($total->compareTo(BigDecimal::of((string) $order->grand_total)) > 0) {
            throw new BusinessException('مبلغ الإشعار يتجاوز المتبقي غير المسدد من هذا الطلب.');
        }
    }

    private function settleOrderIfCovered(int $orderId, int $adminId, string $noticeNumber): void
    {
        $order = Order::query()->lockForUpdate()->find($orderId);

        if ($order === null || $order->payment_status !== 'unpaid') {
            return;
        }

        $approved = (string) WholesalePaymentNotice::query()
            ->where('order_id', $order->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->sum('amount');

        if (BigDecimal::of($approved)->compareTo(BigDecimal::of((string) $order->grand_total)) >= 0) {
            $this->markOrderPaid($order, $adminId, "تسوية آجل بالإشعار {$noticeNumber}");
        }
    }

    // FIFO allocation settles oldest unpaid orders first using available unallocated notices.
    private function settleFreeNoticeFifo(Customer $customer, int $adminId, string $noticeNumber): void
    {
        $available = $this->freePoolTotal($customer)->minus($this->allocatedTotal($customer))
            ->toScale(2, RoundingMode::HalfUp);
        if ($available->isLessThanOrEqualTo('0')) {
            return;
        }

        $branchId = app(BranchAccess::class)->restrictedBranch(User::findOrFail($adminId));
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->when($branchId !== null, fn ($q) => $q->where('allocated_branch_id', $branchId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($orders as $order) {
            if ($available->isLessThanOrEqualTo('0')) {
                break;
            }
            $linked = BigDecimal::of((string) WholesalePaymentNotice::query()
                ->where('order_id', $order->id)
                ->where('status', WholesalePaymentNoticeStatus::Approved->value)
                ->sum('amount'))->toScale(2, RoundingMode::HalfUp);
            $alreadyAllocated = BigDecimal::of((string) WholesalePaymentAllocation::query()
                ->where('order_id', $order->id)
                ->sum('amount'))->toScale(2, RoundingMode::HalfUp);
            $need = BigDecimal::of((string) $order->grand_total)->minus($linked)->minus($alreadyAllocated)
                ->toScale(2, RoundingMode::HalfUp);
            if ($need->isLessThanOrEqualTo('0')) {
                $this->markOrderPaid($order, $adminId, "تسوية آجل بالإشعار الحر {$noticeNumber}");
                continue;
            }
            if ($available->compareTo($need) >= 0) {
                $this->consumeFreePool($customer, $need, (int) $order->id, $adminId);
                $this->markOrderPaid($order, $adminId, "تسوية آجل بالإشعار الحر {$noticeNumber}");
                $available = $available->minus($need)->toScale(2, RoundingMode::HalfUp);
            } else {
                break;
            }
        }
    }

    private function consumeFreePool(Customer $customer, BigDecimal $need, int $orderId, int $adminId): void
    {
        $notices = $this->freeNoticesQuery($customer)->orderBy('id')->lockForUpdate()->get();
        $remaining = $need->toScale(2, RoundingMode::HalfUp);

        foreach ($notices as $notice) {
            if ($remaining->isLessThanOrEqualTo('0')) {
                break;
            }
            $used = BigDecimal::of((string) WholesalePaymentAllocation::query()
                ->where('notice_id', $notice->id)
                ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
                ->sum('amount'))->toScale(2, RoundingMode::HalfUp);
            $free = BigDecimal::of((string) $notice->amount)->minus($used)->toScale(2, RoundingMode::HalfUp);
            if ($free->isLessThanOrEqualTo('0')) {
                continue;
            }
            $take = $free->compareTo($remaining) >= 0 ? $remaining : $free;
            WholesalePaymentAllocation::create([
                'notice_id' => $notice->id,
                'order_id' => $orderId,
                'amount' => (string) $take,
                'created_by' => $adminId,
            ]);
            $remaining = $remaining->minus($take)->toScale(2, RoundingMode::HalfUp);
        }
    }

    // Record status transition in audit history rather than performing a silent update.
    private function markOrderPaid(Order $order, int $adminId, string $notes): void
    {
        $order->update(['payment_status' => 'paid']);
        $order->statusHistories()->create([
            'from_status' => $order->status,
            'to_status' => $order->status,
            'changed_by_type' => (new User)->getMorphClass(),
            'changed_by_id' => $adminId,
            'notes' => mb_substr($notes, 0, 500),
        ]);
    }

    private function resolveTreasury(?int $treasuryId): Treasury
    {
        if ($treasuryId !== null) {
            // Receipts must go to bank accounts or main safes, not active POS drawers during open shifts.
            $treasury = Treasury::query()->findOrFail($treasuryId);
            if (! $treasury->is_active) {
                throw new BusinessException('الخزينة المحددة غير نشطة.');
            }
            if (! in_array($treasury->type, [TreasuryType::BankAccount, TreasuryType::MainSafe], true)) {
                throw new BusinessException('اعتماد إشعار السداد مسموح للخزينة البنكية أو الرئيسية فقط.');
            }

            return $treasury;
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
