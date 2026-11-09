<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\ToolProvider;
use App\Services\Ai\Storefront\Tools\CreateBannerTool;
use App\Services\Ai\Storefront\Tools\DiagnoseCartTool;
use App\Services\Ai\Storefront\Tools\ExplainStoreHomeTool;
use App\Services\Ai\Storefront\Tools\PlaceAssistedOrderTool;
use App\Services\Ai\Storefront\Tools\PreviewCheckoutTool;
use App\Services\Ai\Storefront\Tools\ReorderBannersTool;
use App\Services\Ai\Storefront\Tools\SetBannerStatusTool;
use App\Services\Ai\Storefront\Tools\StorefrontCapabilitiesTool;
use App\Services\Ai\Storefront\Tools\UpdateBannerTool;
use App\Services\Shared\SettingService;

final class StorefrontToolProvider implements ToolProvider
{
    /** @var array<string, AgentTool>|null */
    private ?array $toolsCache = null;

    /** @var list<class-string<AgentTool>> */
    private const TOOL_CLASSES = [
        ExplainStoreHomeTool::class,
        DiagnoseCartTool::class,
        PreviewCheckoutTool::class,
        StorefrontCapabilitiesTool::class,
        CreateBannerTool::class,
        UpdateBannerTool::class,
        SetBannerStatusTool::class,
        ReorderBannersTool::class,
        PlaceAssistedOrderTool::class,
    ];

    public function __construct(private SettingService $settings) {}

    public function tools(): array
    {
        if ($this->toolsCache !== null) {
            return $this->toolsCache;
        }
        $tools = [];
        foreach (self::TOOL_CLASSES as $class) {
            if (class_exists($class)) {
                /** @var AgentTool $tool */
                $tool = app($class);
                $tools[$tool->name()] = $tool;
            }
        }

        return $this->toolsCache = $tools;
    }

    public function enabled(): bool
    {
        return $this->settings->getBool('ai_storefront_tools_enabled', false);
    }

    public function isToolHidden(string $name): bool
    {
        return $name === 'place_assisted_order' && ! $this->settings->getBool('ai_storefront_place_order_enabled', false);
    }

    public function domain(): string
    {
        return 'storefront';
    }

    public function usesGlobalAdminLock(): bool
    {
        return false;
    }

    public function canReadEntity(User $actor, string $type): ?bool
    {
        if (! in_array($type, ['banner', 'cart'], true)) {
            return null;
        }
        if (! $this->enabled()) {
            return false;
        }

        return match ($type) {
            'banner' => $actor->canAny(['store.manage', 'settings.view']),
            'cart' => $actor->can('orders.view'),
            default => false,
        };
    }

    public function systemPromptSection(User $actor): ?string
    {
        if (! $this->enabled() || ! $actor->canAny(['orders.view', 'store.manage', 'settings.view'])) {
            return null;
        }

        return implode("\n", [
            'دومين المتجر:',
            '— المفضلة على جهاز المتصفح فقط. متقولش إنك شايفها.',
            '— معاينة الشراء لا تنشئ طلبًا ولا تحجز مخزونًا. payment_url دايمًا null.',
            '— إنشاء الطلب بأداة place_assisted_order فقط، وهي مقفولة إلا لو الإعداد فاتحها، ولعميل معروف.',
            '— بانر جديد محتاج صورة مرفوعة. من غيرها قول: ارفع الصورة من شاشة المتجر أو أرفقها هنا.',
            '— أسعار السلة من diagnose_cart وpreview_checkout، مش من حسابك.',
        ]);
    }
}
