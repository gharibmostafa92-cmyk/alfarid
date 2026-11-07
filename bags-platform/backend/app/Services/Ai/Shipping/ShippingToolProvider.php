<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\ToolProvider;
use App\Services\Ai\Shipping\Tools\DeleteShippingRateTool;
use App\Services\Ai\Shipping\Tools\DiagnoseShippingCoverageTool;
use App\Services\Ai\Shipping\Tools\ExplainShippingQuoteTool;
use App\Services\Ai\Shipping\Tools\GetShippingCompanyTool;
use App\Services\Ai\Shipping\Tools\GetShippingZoneTool;
use App\Services\Ai\Shipping\Tools\ListShippingSetupTool;
use App\Services\Ai\Shipping\Tools\SetShippingCompanyStatusTool;
use App\Services\Ai\Shipping\Tools\SyncZoneCitiesTool;
use App\Services\Ai\Shipping\Tools\UpsertShippingCompanyTool;
use App\Services\Ai\Shipping\Tools\UpsertShippingRateTool;
use App\Services\Ai\Shipping\Tools\UpsertShippingZoneTool;
use App\Services\Ai\Shipping\Tools\WhatIfShippingChangeTool;
use App\Services\SettingService;

final class ShippingToolProvider implements ToolProvider
{
    /** @var array<string, AgentTool>|null */
    private ?array $toolsCache = null;

    /** @var list<class-string<AgentTool>> */
    private const TOOL_CLASSES = [
        ExplainShippingQuoteTool::class,
        DiagnoseShippingCoverageTool::class,
        ListShippingSetupTool::class,
        GetShippingCompanyTool::class,
        GetShippingZoneTool::class,
        WhatIfShippingChangeTool::class,
        UpsertShippingCompanyTool::class,
        SetShippingCompanyStatusTool::class,
        UpsertShippingZoneTool::class,
        SyncZoneCitiesTool::class,
        UpsertShippingRateTool::class,
        DeleteShippingRateTool::class,
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
        return $this->settings->getBool('ai_shipping_tools_enabled', false);
    }

    public function isToolHidden(string $name): bool
    {
        return false;
    }

    public function domain(): string
    {
        return 'shipping';
    }

    public function usesGlobalAdminLock(): bool
    {
        return false;
    }

    public function canReadEntity(User $actor, string $type): ?bool
    {
        if (! in_array($type, ['shipping_company', 'shipping_zone', 'shipping_rate'], true)) {
            return null;
        }
        if (! $this->enabled()) {
            return false;
        }

        return $actor->can('shipping.view');
    }

    public function systemPromptSection(User $actor): ?string
    {
        if (! $this->enabled() || ! $actor->can('shipping.view')) {
            return null;
        }

        return implode("\n", [
            'دومين الشحن:',
            '— تكلفة التوصيل ورسوم التحصيل من explain_shipping_quote فقط. متحسبش بنفسك.',
            '— الفائز أول شركة نشطة مرتبة بمعرّف الشركة ثم معرّف التعريفة، مش الأرخص.',
            '— عتبة الشحن المجاني بتصغّر التكلفة فقط. رسوم التحصيل ما بتسقطش.',
            '— مدينة بلا تعريفة نشطة: التكلفة هي احتياط المدينة ورسوم التحصيل صفر.',
            '— كوبون free_shipping طبقة تانية بعد العرض، ومش جزء من تعريفة الشحن.',
            '— مفاتيح تكامل الشركات ممنوعة في الشات. قول إنها محفوظة أو لا، ومتطلبهاش.',
            '— أي تعديل اقتراح لحد التأكيد.',
        ]);
    }
}
