<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\ShippingCompany;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Shipping\ShippingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class GetShippingCompanyTool implements AgentTool
{
    public function name(): string
    {
        return 'get_shipping_company';
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
        return ['shipping.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تفاصيل شركة شحن من غير مفاتيح التكامل. credentials_stored يقول بس إذا كانت المفاتيح محفوظة.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['company_id'],
                'properties' => (object) ['company_id' => ['type' => 'INTEGER']],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $company = ShippingCompany::query()->withCount('rates')->find((int) ($args['company_id'] ?? 0));
        if (! $company) {
            return ToolOutcome::denied('شركة الشحن غير موجودة.', auditable: false);
        }

        return ToolOutcome::data(['company' => ShippingPresenter::company($company)]);
    }
}
