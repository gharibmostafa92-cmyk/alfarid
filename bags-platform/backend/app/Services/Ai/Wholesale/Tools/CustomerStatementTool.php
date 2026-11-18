<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\Wholesale\WholesaleAccessGuard;
use App\Services\Inventory\BranchAccess;
use App\Services\Wholesale\WholesalePaymentNoticeService;

final class CustomerStatementTool implements AgentTool
{
    public function __construct(
        private WholesalePaymentNoticeService $notices,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'customer_statement';
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
        return ['payment_notices.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'كشف حساب تاجر لفترة. اقبل جملة زي "الشهر اللي فات" في period.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'customer_id' => ['type' => 'INTEGER'],
                    'period' => ['type' => 'STRING'],
                    'from' => ['type' => 'STRING'],
                    'to' => ['type' => 'STRING'],
                ],
                'required' => ['customer_id'],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        if ($denied = WholesaleAccessGuard::denyIfNoCentralAccess($this->branches, $actor)) {
            return $denied;
        }
        $args = FinanceArgs::normalize($args);
        $customer = Customer::query()->find((int) ($args['customer_id'] ?? 0));
        if ($customer === null) {
            return ToolOutcome::denied('التاجر مش موجود.', auditable: false);
        }

        $from = null;
        $to = null;
        if (! empty($args['from']) || ! empty($args['to'])) {
            try {
                $fromDate = ! empty($args['from']) ? \Carbon\Carbon::parse((string) $args['from']) : null;
                $toDate = ! empty($args['to']) ? \Carbon\Carbon::parse((string) $args['to']) : null;
            } catch (\Throwable) {
                return ToolOutcome::denied('صيغة التاريخ في from أو to غير صالحة.', auditable: false);
            }
            if ($fromDate !== null && $toDate !== null && $fromDate->gt($toDate)) {
                return ToolOutcome::denied('تاريخ البداية لازم يكون قبل أو يساوي تاريخ النهاية.', auditable: false);
            }
            $from = $fromDate?->toDateString();
            $to = $toDate?->toDateString();
        } elseif (! empty($args['period'])) {
            $range = FinanceArgs::parseDateRange((string) $args['period']);
            $from = $range['from'];
            $to = $range['to'];
        }

        $statement = $this->notices->statement($customer, $from, $to);
        $rows = array_map(fn (array $entry) => [
            $entry['date'],
            $entry['reference'],
            $entry['description'],
            $entry['debit'],
            $entry['credit'],
            $entry['balance'],
        ], $statement['entries']);

        return $this->presenter->present($actor, [
            'customer_id' => $customer->id,
            'from' => $from,
            'to' => $to,
            ...$statement,
        ], [
            'type' => 'table',
            'columns' => ['التاريخ', 'المرجع', 'البيان', 'مدين', 'دائن', 'الرصيد'],
            'rows' => $rows,
        ]);
    }
}
