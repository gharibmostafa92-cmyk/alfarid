<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Services\Inventory\BranchAccess;
use App\Services\Pos\TreasuryService;
use App\Services\Shared\PrivateAttachments;
use App\Services\Shared\SettingService;

use App\Enums\OrderStatus;
use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryType;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Events\Wholesale\PaymentNoticeReviewed;
use App\Events\Wholesale\PaymentNoticeSubmitted;
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
use Illuminate\Database\UniqueConstraintViolationException;
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
     * @param  Customer  $customer  Submitting wholesale customer
     * @param  array<string, mixed>  $data  Notice metadata
     * @param  UploadedFile  $receipt  Proof of payment file
     * @return WholesalePaymentNotice Created pending notice
     *
     * @throws BusinessException If linked order is invalid or capacity exceeded
     */
    public function create(Customer $customer, array $data, UploadedFile $receipt): WholesalePaymentNotice
    {
        return PrivateAttachments::transaction(function (PrivateAttachments $files) use ($customer, $data, $receipt): WholesalePaymentNotice {
            // Serialize free and linked submissions for the same customer before
            // checking the bank reference, including requests with different files.
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $reference = trim($data['reference_number']);
            if (WholesalePaymentNotice::query()->where('customer_id', $customer->id)
                ->whereReferenceNumber($reference)->exists()) {
                throw new BusinessException('رقم مرجع السداد مسجل بالفعل لهذا التاجر.', [], 409);
            }

            $amount = BigDecimal::of((string) $data['amount'])->toScale(2, RoundingMode::HalfUp);
            $transferDate = Carbon::parse((string) $data['transfer_date'])->toDateString();
            $crossCustomer = WholesalePaymentNotice::query()
                ->where('customer_id', '!=', $customer->id)
                ->where('status', '!=', WholesalePaymentNoticeStatus::Rejected->value)
                ->whereReferenceNumber($reference)
                ->where('amount', (string) $amount)
                ->whereDate('transfer_date', $transferDate)
                ->first();
            if ($crossCustomer !== null) {
                throw new BusinessException("نفس المرجع والمبلغ والتاريخ مسجّلين لإشعار {$crossCustomer->notice_number} من حساب تاجر آخر.", [], 409);
            }

            $realPath = $receipt->getRealPath();
            if ($realPath === false) {
                throw new BusinessException('تعذر قراءة إيصال السداد.');
            }
            $hash = hash_file('sha256', $realPath);
            $sameReceipt = WholesalePaymentNotice::query()
                ->where('receipt_sha256', $hash)
                ->where('status', '!=', WholesalePaymentNoticeStatus::Rejected->value)
                ->first();
            if ($sameReceipt !== null) {
                throw new BusinessException("نفس الإيصال اترفع قبل كده ({$sameReceipt->notice_number}).", [], 409);
            }

            $order = null;

            if (! empty($data['order_id'])) {
                // Pessimistic lock inside transaction serializes concurrent submissions against remaining balance.
                $order = $this->assertCreditableOrder($customer, (int) $data['order_id']);
                $this->assertOrderCapacity($order, (string) $data['amount']);
            }

            $path = $files->store($receipt, "wholesale_payment_notices/{$customer->id}");

            try {
                $notice = WholesalePaymentNotice::create([
                    'notice_number' => $this->settings->generateNextNumber('payment_notice'),
                    'customer_id' => $customer->id,
                    'order_id' => $order?->id,
                    'amount' => (string) $amount,
                    'payment_method' => $data['payment_method'],
                    'bank_name' => $data['bank_name'],
                    'reference_number' => $reference,
                    'transfer_date' => $transferDate,
                    'receipt_file_path' => $path,
                    'receipt_sha256' => $hash,
                    'status' => WholesalePaymentNoticeStatus::PendingReview,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessException('نفس الإيصال اترفع قبل كده.', [], 409);
            }

            PaymentNoticeSubmitted::dispatch($notice);

            return $notice;
        }, [[$receipt, 'wholesale_payment_notices/'.$customer->id]]);
    }

    /**
     * Computes the current net debt for a wholesale customer (negative implies credit balance).
     *
     * @param  Customer  $customer  Wholesale customer
     * @return float Current outstanding debt or negative advance credit
     */
    public function outstandingBalance(Customer $customer): float
    {
        return (float) (string) $this->outstandingBalanceDecimal($customer);
    }

    /**
     * @return array{debt: string, linked_open_notices: string, free_pool: string, allocated: string, allocated_open: string, floating_credit: string, outstanding: string}
     */
    public function balanceBreakdown(Customer $customer): array
    {
        $debt = BigDecimal::of((string) Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('grand_total'))->toScale(2, RoundingMode::HalfUp);

        // Fully paid orders and their linked notices are excluded to avoid double deduction.
        $linkedOpen = BigDecimal::of((string) WholesalePaymentNotice::query()
            ->where('customer_id', $customer->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->whereNotNull('order_id')
            ->whereExists(function ($exists): void {
                $exists->selectRaw('1')->from('orders as o')
                    ->whereColumn('o.id', 'wholesale_payment_notices.order_id')
                    ->where('o.payment_status', 'unpaid')
                    ->where('o.status', '!=', OrderStatus::Cancelled->value);
            })
            ->sum('amount'))->toScale(2, RoundingMode::HalfUp);

        $free = $this->freePoolTotal($customer);
        $allocated = $this->allocatedTotal($customer);
        // Partial FIFO allocations consume free money against orders that stay
        // unpaid, so debt still counts their full totals: subtract the applied
        // amount explicitly or the payment would vanish from the balance.
        $allocatedOpen = $this->allocatedOpenTotal($customer);
        $floating = $free->minus($allocated)->toScale(2, RoundingMode::HalfUp);
        $outstanding = $debt->minus($linkedOpen)->minus($floating)->minus($allocatedOpen)->toScale(2, RoundingMode::HalfUp);

        return [
            'debt' => (string) $debt,
            'linked_open_notices' => (string) $linkedOpen,
            'free_pool' => (string) $free,
            'allocated' => (string) $allocated,
            'allocated_open' => (string) $allocatedOpen,
            'floating_credit' => (string) $floating,
            'outstanding' => (string) $outstanding,
        ];
    }

    private function outstandingBalanceDecimal(Customer $customer): BigDecimal
    {
        return BigDecimal::of($this->balanceBreakdown($customer)['outstanding']);
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

    private function allocatedOpenTotal(Customer $customer): BigDecimal
    {
        return BigDecimal::of((string) WholesalePaymentAllocation::query()
            ->whereHas('notice', fn ($q) => $q->where('customer_id', $customer->id))
            ->whereHas('order', fn ($q) => $q
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->where('payment_status', 'unpaid'))
            ->sum('amount'))->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Generates a chronological statement of account including debits, credits, and opening balance.
     *
     * @param  Customer  $customer  Wholesale customer
     * @param  string|null  $from  Start date (inclusive, YYYY-MM-DD)
     * @param  string|null  $to  End date (inclusive, YYYY-MM-DD)
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
            ->get(['id', 'notice_number', 'payment_method', 'amount', 'approved_at', 'transfer_date', 'updated_at'])
            ->each(fn (WholesalePaymentNotice $notice) => $entries->push([
                'date' => ($notice->approved_at ?? $notice->transfer_date ?? $notice->updated_at)->copy(),
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
                'reference' => $entry['reference'],
                'description' => $entry['description'],
                'debit' => $entry['type'] === 'debit' ? number_format($entry['amount'], 2, '.', '') : '0.00',
                'credit' => $entry['type'] === 'credit' ? number_format($entry['amount'], 2, '.', '') : '0.00',
                'balance' => number_format($running, 2, '.', ''),
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
     * @param  WholesalePaymentNotice  $notice  Target notice
     * @param  int  $adminId  Approving staff member ID
     * @param  int|null  $treasuryId  Destination treasury ID
     * @param  string|null  $adminNotes  Optional administrative review notes
     * @return WholesalePaymentNotice Updated notice instance
     *
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

            if ($notice->order_id !== null) {
                $order = Order::query()->lockForUpdate()->findOrFail($notice->order_id);
                if ($order->payment_status === 'paid') {
                    throw new BusinessException('الطلب المرتبط تم سداده بالفعل؛ لا يمكن اعتماد إشعار السداد.');
                }
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
                'approved_at' => now(),
                'admin_notes' => $adminNotes ?? $notice->admin_notes,
                'treasury_id' => $treasury->id,
                'treasury_transaction_id' => $transaction->id,
            ]);

            if ($notice->order_id !== null) {
                $this->settleOrderIfCovered((int) $notice->order_id, $adminId, $notice->notice_number);
            }
            $this->settleFreeNoticeFifo($customer, $adminId, $notice->notice_number);

            $notice = $notice->load(['customer', 'order', 'treasury', 'treasuryTransaction']);
            PaymentNoticeReviewed::dispatch($notice);

            return $notice;
        });
    }

    /**
     * Rejects a pending wholesale payment notice.
     *
     * @param  WholesalePaymentNotice  $notice  Target notice
     * @param  int  $adminId  Reviewing staff member ID
     * @param  string|null  $adminNotes  Reason for rejection
     * @return WholesalePaymentNotice Updated notice instance
     *
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

            $notice = $notice->load(['customer', 'order']);
            PaymentNoticeReviewed::dispatch($notice);

            return $notice;
        });
    }

    /**
     * Reverses an approved notice: treasury reversal, allocation removal, and unpaid orders.
     * This is an audited financial action, not the 15-minute AI undo window.
     *
     * @throws BusinessException If the notice is not an approved, unreversed receipt.
     */
    public function reverse(WholesalePaymentNotice $notice, int $adminId, string $reason): WholesalePaymentNotice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('سبب عكس الاعتماد مطلوب.');
        }

        return DB::transaction(function () use ($notice, $adminId, $reason): WholesalePaymentNotice {
            $notice = WholesalePaymentNotice::query()->lockForUpdate()->findOrFail($notice->id);
            Customer::query()->lockForUpdate()->findOrFail($notice->customer_id);

            if ($notice->status !== WholesalePaymentNoticeStatus::Approved || $notice->reversed_at !== null) {
                throw new BusinessException('لا يمكن عكس إشعار غير معتمد أو سبق عكسه.');
            }
            if ($notice->treasury_id === null) {
                throw new BusinessException('الإشعار من غير حركة خزينة، لا يمكن عكسه.');
            }

            $orderIds = WholesalePaymentAllocation::query()
                ->where('notice_id', $notice->id)
                ->pluck('order_id')
                ->all();
            if ($notice->order_id !== null) {
                $orderIds[] = (int) $notice->order_id;
            }
            WholesalePaymentAllocation::query()->where('notice_id', $notice->id)->delete();

            $reversal = $this->treasury->record([
                'treasury_id' => $notice->treasury_id,
                'type' => TreasuryTransactionType::CustomerReceiptReversal,
                'amount' => (string) BigDecimal::of((string) $notice->amount)->negated()->toScale(2, RoundingMode::HalfUp),
                'reference_type' => 'wholesale_payment_notice',
                'reference_id' => $notice->id,
                'created_by' => $adminId,
                'notes' => "عكس اعتماد إشعار سداد {$notice->notice_number}: {$reason}",
            ]);

            $notice->update([
                'status' => WholesalePaymentNoticeStatus::Reversed,
                'reversed_at' => now(),
                'reversal_treasury_transaction_id' => $reversal->id,
                'admin_notes' => $reason,
                'reviewed_by' => $adminId,
            ]);

            foreach (array_unique(array_map('intval', $orderIds)) as $orderId) {
                $this->reopenOrderIfUncovered($orderId, $adminId, $notice->notice_number);
            }

            return $notice->load(['customer', 'order', 'treasury']);
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

    /**
     * Read-only allocation preview. Uses the same planner as settlement and writes nothing.
     *
     * @return array{notice_id: int, notice_number: string, amount: string, linked_order: array<string, mixed>|null, fifo: list<array<string, mixed>>}
     */
    public function previewNoticeAllocation(WholesalePaymentNotice $notice, ?int $branchId = null): array
    {
        return DB::transaction(function () use ($notice, $branchId): array {
            $customer = Customer::query()->lockForUpdate()->findOrFail($notice->customer_id);
            $extra = '0.00';
            $linked = null;

            if ($notice->order_id !== null) {
                $order = Order::query()->lockForUpdate()->find($notice->order_id);
                if ($order !== null) {
                    $approved = BigDecimal::of((string) WholesalePaymentNotice::query()
                        ->where('order_id', $order->id)
                        ->where('status', WholesalePaymentNoticeStatus::Approved->value)
                        ->when($notice->status !== WholesalePaymentNoticeStatus::Approved, fn ($q) => $q->whereKeyNot($notice->id))
                        ->sum('amount'));
                    $incoming = $notice->status === WholesalePaymentNoticeStatus::Approved
                        ? BigDecimal::zero()
                        : BigDecimal::of((string) $notice->amount);
                    $already = BigDecimal::of((string) WholesalePaymentAllocation::query()->where('order_id', $order->id)->sum('amount'));
                    $remaining = BigDecimal::of((string) $order->grand_total)->minus($approved)->minus($incoming)->minus($already)
                        ->toScale(2, RoundingMode::HalfUp);
                    $linked = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'remaining_after' => (string) ($remaining->isNegative() ? BigDecimal::zero()->toScale(2, RoundingMode::HalfUp) : $remaining),
                        'closes' => $remaining->isLessThanOrEqualTo('0'),
                    ];
                }
            } elseif ($notice->status !== WholesalePaymentNoticeStatus::Approved) {
                $extra = (string) BigDecimal::of((string) $notice->amount)->toScale(2, RoundingMode::HalfUp);
            }

            return [
                'notice_id' => $notice->id,
                'notice_number' => $notice->notice_number,
                'amount' => (string) $notice->amount,
                'linked_order' => $linked,
                'fifo' => $this->planFreeAllocation($customer, $extra, $branchId),
            ];
        });
    }

    /** @return list<array{notice_id: int, notice_number: string, customer_id: int, reason: string}> */
    public function receiptCollisions(WholesalePaymentNotice $notice): array
    {
        $collisions = [];
        $mapCollision = static fn (WholesalePaymentNotice $other, string $reason): array => [
            'notice_id' => $other->id,
            'notice_number' => $other->notice_number,
            'customer_id' => $other->customer_id,
            'reason' => $reason,
        ];

        if ($notice->receipt_sha256 !== null) {
            $sameFile = WholesalePaymentNotice::query()
                ->where('receipt_sha256', $notice->receipt_sha256)
                ->whereKeyNot($notice->id)
                ->where('status', '!=', WholesalePaymentNoticeStatus::Rejected->value)
                ->get(['id', 'notice_number', 'customer_id']);
            foreach ($sameFile as $other) {
                $collisions[] = $mapCollision($other, 'same_receipt');
            }
        }

        $sameTransfer = WholesalePaymentNotice::query()
            ->whereKeyNot($notice->id)
            ->where('customer_id', '!=', $notice->customer_id)
            ->where('status', '!=', WholesalePaymentNoticeStatus::Rejected->value)
            ->whereReferenceNumber((string) $notice->reference_number)
            ->where('amount', (string) $notice->amount)
            ->whereDate('transfer_date', $notice->transfer_date?->toDateString())
            ->get(['id', 'notice_number', 'customer_id']);
        foreach ($sameTransfer as $other) {
            $collisions[] = $mapCollision($other, 'same_reference_amount_date');
        }

        return $collisions;
    }

    // FIFO allocation settles oldest unpaid orders first using available unallocated notices.
    private function settleFreeNoticeFifo(Customer $customer, int $adminId, string $noticeNumber): void
    {
        $branchId = app(BranchAccess::class)->restrictedBranch(User::findOrFail($adminId));
        $this->applyFreePlan($customer, $adminId, $noticeNumber, $this->planFreeAllocation($customer, '0', $branchId));
    }

    /**
     * @return list<array{order_id: int, order_number: string, allocated: string, remaining: string, closes: bool, skipped: bool}>
     */
    private function planFreeAllocation(Customer $customer, string $extraFree, ?int $branchId): array
    {
        $available = $this->freePoolTotal($customer)->minus($this->allocatedTotal($customer))
            ->plus(BigDecimal::of($extraFree))
            ->toScale(2, RoundingMode::HalfUp);
        $mode = (string) $this->settings->get('wholesale_fifo_mode', 'strict');
        if (! in_array($mode, ['strict', 'skip_to_fit', 'partial'], true)) {
            $mode = 'strict';
        }

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->when($branchId !== null, fn ($q) => $q->where('allocated_branch_id', $branchId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $lines = [];
        foreach ($orders as $order) {
            if ($available->isLessThanOrEqualTo('0')) {
                break;
            }
            $covered = $this->orderCoveredAmount((int) $order->id);
            $need = BigDecimal::of((string) $order->grand_total)->minus($covered)
                ->toScale(2, RoundingMode::HalfUp);
            if ($need->isLessThanOrEqualTo('0')) {
                $lines[] = $this->allocationLine($order, '0.00', '0.00', true, false);

                continue;
            }
            if ($available->compareTo($need) >= 0) {
                $lines[] = $this->allocationLine($order, (string) $need, '0.00', true, false);
                $available = $available->minus($need)->toScale(2, RoundingMode::HalfUp);

                continue;
            }
            if ($mode === 'partial') {
                $remaining = $need->minus($available)->toScale(2, RoundingMode::HalfUp);
                $lines[] = $this->allocationLine($order, (string) $available, (string) $remaining, false, false);

                break;
            }
            if ($mode === 'skip_to_fit') {
                $lines[] = $this->allocationLine($order, '0.00', (string) $need, false, true);

                continue;
            }

            $lines[] = $this->allocationLine($order, '0.00', (string) $need, false, false);

            break;
        }

        return $lines;
    }

    /** @param  list<array{order_id: int, order_number: string, allocated: string, remaining: string, closes: bool, skipped: bool}>  $lines */
    private function applyFreePlan(Customer $customer, int $adminId, string $noticeNumber, array $lines): void
    {
        foreach ($lines as $line) {
            $allocated = BigDecimal::of($line['allocated'])->toScale(2, RoundingMode::HalfUp);
            if ($allocated->isGreaterThan('0')) {
                $this->consumeFreePool($customer, $allocated, $line['order_id'], $adminId);
            }
            if ($line['closes']) {
                $order = Order::query()->lockForUpdate()->find($line['order_id']);
                if ($order !== null && $order->payment_status === 'unpaid') {
                    $this->markOrderPaid($order, $adminId, "تسوية آجل بالإشعار الحر {$noticeNumber}");
                }
            }
        }
    }

    /** @return array{order_id: int, order_number: string, allocated: string, remaining: string, closes: bool, skipped: bool} */
    private function allocationLine(Order $order, string $allocated, string $remaining, bool $closes, bool $skipped): array
    {
        return [
            'order_id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'allocated' => $allocated,
            'remaining' => $remaining,
            'closes' => $closes,
            'skipped' => $skipped,
        ];
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

    private function reopenOrderIfUncovered(int $orderId, int $adminId, string $noticeNumber): void
    {
        $order = Order::query()->lockForUpdate()->find($orderId);
        if ($order === null || $order->payment_status !== 'paid') {
            return;
        }

        $covered = $this->orderCoveredAmount((int) $order->id);
        if ($covered->compareTo(BigDecimal::of((string) $order->grand_total)) >= 0) {
            return;
        }

        $this->transitionOrderPaymentStatus($order, 'unpaid', $adminId, "إلغاء تسوية بعد عكس الإشعار {$noticeNumber}");
    }

    // Record status transition in audit history rather than performing a silent update.
    private function markOrderPaid(Order $order, int $adminId, string $notes): void
    {
        $this->transitionOrderPaymentStatus($order, 'paid', $adminId, $notes);
    }

    private function transitionOrderPaymentStatus(Order $order, string $paymentStatus, int $adminId, string $notes): void
    {
        $order->update(['payment_status' => $paymentStatus]);
        $order->statusHistories()->create([
            'from_status' => $order->status,
            'to_status' => $order->status,
            'changed_by_type' => (new User)->getMorphClass(),
            'changed_by_id' => $adminId,
            'notes' => mb_substr($notes, 0, 500),
        ]);
    }

    private function orderCoveredAmount(int $orderId): BigDecimal
    {
        $linked = BigDecimal::of((string) WholesalePaymentNotice::query()
            ->where('order_id', $orderId)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->sum('amount'));
        $allocated = BigDecimal::of((string) WholesalePaymentAllocation::query()
            ->where('order_id', $orderId)
            ->sum('amount'));

        return $linked->plus($allocated)->toScale(2, RoundingMode::HalfUp);
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
