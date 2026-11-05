<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;

final class ExplainOrderMoneyTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'explain_order_money';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['orders.view', 'treasuries.view', 'expenses.view', 'treasury_transfers.view', 'customers.wallet.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'لطلب معين: حالة الدفع وحركات المحفظة وحركات الخزينة المرتبطة مع جملة تفسير وتنبيهات اتساق.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'order_id' => ['type' => 'INTEGER'],
                    'order_number' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        try {
            $branch = $this->branches->restrictedBranch($actor);
        } catch (AuthorizationException) {
            return ToolOutcome::denied('الطلب خارج نطاقك.', auditable: false);
        }
        $orders = Order::query()->with('returnRequests')
            ->when($branch !== null, fn ($query) => $query->where('allocated_branch_id', $branch));
        if (! empty($args['order_id'])) {
            $order = $orders->find((int) $args['order_id']);
        } elseif (! empty($args['order_number'])) {
            $order = $orders->where('order_number', trim((string) $args['order_number']))->first();
        } else {
            return ToolOutcome::denied('حدد الطلب بـ order_id أو order_number.', auditable: false);
        }
        if (! $order) {
            return ToolOutcome::denied('الطلب مش موجود.', auditable: false);
        }

        $returnIds = $order->returnRequests->pluck('id')->all();
        $walletTxs = CustomerWalletTransaction::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('reference_type', ['order', Order::class])->where('reference_id', $order->id))
                ->orWhere(fn ($w) => $w->whereIn('reference_type', ['return_request', ReturnRequest::class])->whereIn('reference_id', $returnIds === [] ? [-1] : $returnIds)))
            ->orderBy('id')->get()->map(function (CustomerWalletTransaction $t) {
                $isReturn = in_array($t->reference_type, ['return_request', ReturnRequest::class], true);

                return [
                    'at' => $t->created_at?->toIso8601String(),
                    'type' => (string) $t->type,
                    'type_label' => $this->presenter->walletTypeLabel((string) $t->type),
                    'amount' => (float) $t->amount,
                    'reference_label' => $isReturn ? 'مرتجع #'.$t->reference_id : 'طلب #'.$t->reference_id,
                ];
            })->values()->all();

        $treasuryTxs = TreasuryTransaction::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('reference_type', ['order', Order::class])->where('reference_id', $order->id))
                ->orWhere(fn ($w) => $w->whereIn('reference_type', ['return_request', ReturnRequest::class])->whereIn('reference_id', $returnIds)))
            ->when($branch !== null, fn ($q) => $q->whereHas('treasury', fn ($treasury) => $treasury->where('branch_id', $branch)))
            ->with('treasury:id,name')->orderBy('id')->get()->map(function (TreasuryTransaction $tx) {
                $txType = $tx->type instanceof \BackedEnum ? $tx->type->value : (string) $tx->type;

                return [
                    'at' => $tx->created_at?->toIso8601String(),
                    'treasury_id' => $tx->treasury_id,
                    'treasury_name' => $tx->treasury?->name,
                    'type' => $txType,
                    'type_label' => $this->presenter->transactionTypeLabel($txType),
                    'amount' => (float) $tx->amount,
                ];
            })->values()->all();

        $total = (float) $order->grand_total;
        $walletIn = array_sum(array_map(fn ($w) => $w['amount'] < 0 ? abs($w['amount']) : 0.0, $walletTxs));
        $treasuryNet = array_sum(array_column($treasuryTxs, 'amount'));
        $hasRefundTx = count(array_filter(
            $walletTxs,
            fn ($w) => in_array($w['type'], ['return_refund', 'refund', 'checkout_refund'], true) && $w['amount'] > 0
        )) > 0 || count(array_filter(
            $treasuryTxs,
            fn ($t) => in_array($t['type'], ['pos_return'], true)
        )) > 0;

        $warnings = [];
        if (in_array($order->payment_status, ['paid', 'partially_refunded'], true) && $walletTxs === [] && $treasuryTxs === []) {
            $warnings[] = 'حالة الدفع مسددة لكن لا توجد أي حركة مالية مرتبطة بالطلب.';
        }
        if (in_array($order->payment_status, ['refunded', 'partially_refunded', 'refund_pending'], true) && ! $hasRefundTx) {
            $warnings[] = 'الطلب مسترد لكن لا يوجد قيد استرداد مرتبط به.';
        }
        $movementSum = $walletIn + max(0.0, $treasuryNet);
        if ($order->payment_status === 'paid' && $movementSum > 0 && abs($movementSum - $total) > 0.005) {
            $warnings[] = 'مجموع الحركات ('.$this->presenter->money($movementSum).') لا يساوي إجمالي الطلب ('.$this->presenter->money($total).').';
        }

        $explanation = $this->buildExplanation($order, $total, $walletIn, $treasuryTxs, $walletTxs);

        $data = [
            'order' => [
                'id' => $order->id,
                'number' => $order->order_number,
                'total' => $total,
                'payment_status' => (string) $order->payment_status,
                'payment_method' => (string) ($order->payment_method ?? ''),
            ],
            'wallet' => $walletTxs,
            'treasury' => $treasuryTxs,
            'explanation' => ['untrusted_text' => $explanation],
            'consistency_warnings' => $warnings,
        ];

        return $this->presenter->present($actor, $data, array_merge(['type' => 'money_trail'], $data));
    }

    /**
     * @param array{grand_total:mixed,payment_status:mixed,payment_method:mixed} $order
     * @param list<array{amount:float}> $treasuryTxs
     * @param list<array{amount:float}> $walletTxs
     */
    private function buildExplanation($order, float $total, float $walletIn, array $treasuryTxs, array $walletTxs): string
    {
        $money = fn (float $v): string => $this->presenter->money($v);
        $method = (string) ($order->payment_method ?? '');
        $status = (string) $order->payment_status;

        if ($status === 'unpaid' && $walletTxs === [] && $treasuryTxs === []) {
            return 'الطلب غير مدفوع ولا توجد أي حركات مالية مرتبطة به.';
        }
        if (in_array($method, ['wallet', 'mixed'], true) && $walletIn > 0 && $treasuryTxs === []) {
            return 'الطلب مدفوع بالمحفظة '.$money($walletIn).'؛ ماحصلش أي حركة على الخزينة لأن الفلوس ما دخلتش درج.';
        }
        if ($treasuryTxs !== [] && $walletTxs === []) {
            $net = array_sum(array_column($treasuryTxs, 'amount'));

            return 'الطلب مدفوع نقدًا '.$money($total).' وسُجلت '.count($treasuryTxs).' حركة على الخزينة بصافي '.$money($net).'.';
        }
        if ($walletTxs !== [] && $treasuryTxs !== []) {
            return 'الطلب مدفوع جزئيًا بالمحفظة ('.$money($walletIn).') والباقي نقدًا؛ راجع تفاصيل الحركات أعلاه.';
        }

        return 'إجمالي الطلب '.$money($total).' وحالة الدفع: '.$status.'.';
    }
}
