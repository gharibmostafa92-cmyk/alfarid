<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\Customer\CustomerType;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\Wholesale\WholesaleAccessGuard;
use App\Services\Inventory\BranchAccess;
use App\Services\Wholesale\WholesalePaymentNoticeService;

final class ExplainOutstandingTool implements AgentTool
{
    public function __construct(
        private WholesalePaymentNoticeService $notices,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'explain_outstanding';
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
            'description' => 'يفصّل مديونية التاجر: الديون، الإشعارات المربوطة المفتوحة، الرصيد الحر، والمخصص منه.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'customer_id' => ['type' => 'INTEGER'],
                    'summary_only' => ['type' => 'BOOLEAN', 'description' => 'استخدمه لسؤال عليه كام أو فاضل كام من الحد.'],
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
        $customer = Customer::query()->find((int) ($args['customer_id'] ?? 0));
        if ($customer === null || ! in_array($customer->type?->value, CustomerType::commercialValues(), true)) {
            return ToolOutcome::denied('التاجر مش موجود.', auditable: false);
        }

        $summary = ($args['summary_only'] ?? false) === true;
        $breakdown = $summary ? [] : $this->notices->balanceBreakdown($customer);
        $credit = $summary
            ? $this->notices->accountCredit($customer)
            : $this->notices->creditFromOutstanding($customer, $breakdown['outstanding']);
        $data = [
            'customer_id' => $customer->id,
            'customer' => $this->presenter->untrusted($customer->company_name ?: $customer->name),
            ...$breakdown,
            ...$credit,
        ];

        $steps = $summary ? [] : [
            ['label' => 'ديون الطلبات الآجلة المفتوحة', 'amount' => $breakdown['debt']],
            ['label' => 'إشعارات مربوطة لطلبات لسه مفتوحة', 'amount' => $breakdown['linked_open_notices']],
            ['label' => 'رصيد حر من إشعارات غير مربوطة', 'amount' => $breakdown['free_pool']],
            ['label' => 'المخصص من الرصيد الحر', 'amount' => $breakdown['allocated']],
            ['label' => 'الرصيد الحر المتبقي', 'amount' => $breakdown['floating_credit']],
        ];

        return $this->presenter->present($actor, $data, [
            'type' => 'money_trail',
            'title' => 'مديونية التاجر والائتمان المتاح',
            'steps' => [
                ...$steps,
                ['label' => 'عليه', 'amount' => $credit['amount_due']],
                ['label' => 'رصيد دائن', 'amount' => $credit['credit_balance']],
                ['label' => 'حد الائتمان المتاح', 'amount' => $credit['available_credit']],
            ],
        ]);
    }
}
