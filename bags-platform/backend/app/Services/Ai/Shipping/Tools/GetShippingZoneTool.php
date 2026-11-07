<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Shipping\ShippingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class GetShippingZoneTool implements AgentTool
{
    public function name(): string
    {
        return 'get_shipping_zone';
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
            'description' => 'منطقة شحن بمدنها وتعريفاتها.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['zone_id'],
                'properties' => (object) ['zone_id' => ['type' => 'INTEGER']],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $zone = ShippingZone::query()->find((int) ($args['zone_id'] ?? 0));
        if (! $zone) {
            return ToolOutcome::denied('منطقة الشحن غير موجودة.', auditable: false);
        }

        return ToolOutcome::data(['zone' => ShippingPresenter::zone($zone)]);
    }
}
