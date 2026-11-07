<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\WholesalePaymentNoticeService;

final class CustomerStatementTool implements AgentTool
{
    public function __construct(private WholesalePaymentNoticeService $notices) {}

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
        $customer = Customer::query()->find((int) ($args['customer_id'] ?? 0));
        if ($customer === null) {
            return ToolOutcome::denied('التاجر مش موجود.', auditable: false);
        }

        $from = null;
        $to = null;
        if (! empty($args['from']) || ! empty($args['to'])) {
            $from = isset($args['from']) ? (string) $args['from'] : null;
            $to = isset($args['to']) ? (string) $args['to'] : null;
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

        return ToolOutcome::data([
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
