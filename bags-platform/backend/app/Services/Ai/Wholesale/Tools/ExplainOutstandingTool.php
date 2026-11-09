<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Wholesale\WholesalePaymentNoticeService;

final class ExplainOutstandingTool implements AgentTool
{
    public function __construct(private WholesalePaymentNoticeService $notices) {}

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
                'properties' => (object) ['customer_id' => ['type' => 'INTEGER']],
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

        $breakdown = $this->notices->balanceBreakdown($customer);
        $data = [
            'customer_id' => $customer->id,
            'customer' => $customer->company_name ?: $customer->name,
            ...$breakdown,
        ];

        return ToolOutcome::data($data, [
            'type' => 'money_trail',
            'title' => 'ليه الرصيد '.$breakdown['outstanding'],
            'steps' => [
                ['label' => 'ديون الطلبات الآجلة المفتوحة', 'amount' => $breakdown['debt']],
                ['label' => 'إشعارات مربوطة لطلبات لسه مفتوحة', 'amount' => $breakdown['linked_open_notices']],
                ['label' => 'رصيد حر من إشعارات غير مربوطة', 'amount' => $breakdown['free_pool']],
                ['label' => 'المخصص من الرصيد الحر', 'amount' => $breakdown['allocated']],
                ['label' => 'الرصيد الحر المتبقي', 'amount' => $breakdown['floating_credit']],
                ['label' => 'الصافي', 'amount' => $breakdown['outstanding']],
            ],
        ]);
    }
}
