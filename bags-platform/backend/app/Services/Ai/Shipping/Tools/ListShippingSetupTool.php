<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\ShippingCompany;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Shipping\ShippingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class ListShippingSetupTool implements AgentTool
{
    public function name(): string
    {
        return 'list_shipping_setup';
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
            'description' => 'قائمة شركات الشحن والمناطق من غير أي مفاتيح تكامل.',
            'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $companies = ShippingCompany::query()->withCount('rates')->orderBy('id')->limit(50)->get()
            ->map(fn (ShippingCompany $company) => ShippingPresenter::company($company))->values()->all();
        $zones = ShippingZone::query()->withCount(['cities', 'rates'])->orderBy('id')->limit(50)->get()
            ->map(fn (ShippingZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'is_active' => (bool) $zone->is_active,
                'cities_count' => (int) $zone->cities_count,
                'rates_count' => (int) $zone->rates_count,
            ])->values()->all();

        return ToolOutcome::data(['companies' => $companies, 'zones' => $zones]);
    }
}
