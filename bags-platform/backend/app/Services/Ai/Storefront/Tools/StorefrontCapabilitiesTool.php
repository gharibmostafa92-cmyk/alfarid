<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Shared\SettingService;

final class StorefrontCapabilitiesTool implements AgentTool
{
    public function __construct(private SettingService $settings) {}

    public function name(): string
    {
        return 'storefront_capabilities';
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
        return ['orders.view', 'store.manage', 'settings.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'حدود المتجر للمساعد: المفضلة على جهاز العميل، ومفيش رابط دفع.',
            'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        return ToolOutcome::data([
            'wishlist' => 'device_local_only',
            'payment_url' => null,
            'payment_collection' => 'not_integrated',
            'assisted_order_enabled' => $this->settings->getBool('ai_storefront_place_order_enabled', false),
        ]);
    }
}
