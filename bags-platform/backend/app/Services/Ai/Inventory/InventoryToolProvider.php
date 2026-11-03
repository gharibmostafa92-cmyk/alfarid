<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\ToolProvider;
use App\Services\SettingService;

/**
 * أدوات المخزون تقفل الصفوف بترتيب ثابت عبر InventoryService::lockLevels،
 * فلا تحتاج القفل الاستشاري العام (usesGlobalAdminLock = false).
 */
final class InventoryToolProvider implements ToolProvider
{
    /** @var array<string, AgentTool>|null */
    private ?array $toolsCache = null;

    /** @var list<class-string<AgentTool>> */
    private const TOOL_CLASSES = [
        // Phase 1: read tools
        \App\Services\Ai\Inventory\Tools\GetStockLevelsTool::class,
        \App\Services\Ai\Inventory\Tools\StockMatrixTool::class,
        \App\Services\Ai\Inventory\Tools\ListMovementsTool::class,
        \App\Services\Ai\Inventory\Tools\ExplainBalanceTool::class,
        \App\Services\Ai\Inventory\Tools\LowStockListTool::class,
        \App\Services\Ai\Inventory\Tools\DeadStockTool::class,
        \App\Services\Ai\Inventory\Tools\InventoryValuationTool::class,
        \App\Services\Ai\Inventory\Tools\ListTransfersTool::class,
        \App\Services\Ai\Inventory\Tools\GetTransferTool::class,
        \App\Services\Ai\Inventory\Tools\ListStocktakesTool::class,
        \App\Services\Ai\Inventory\Tools\GetStocktakeTool::class,
        \App\Services\Ai\Inventory\Tools\ListProductionReceiptsTool::class,
        \App\Services\Ai\Inventory\Tools\InventoryHealthTool::class,
        // Phase 2: write tools
        \App\Services\Ai\Inventory\Tools\AdjustStockTool::class,
        \App\Services\Ai\Inventory\Tools\ReceiveProductionTool::class,
        // Phase 3: transfer lifecycle
        \App\Services\Ai\Inventory\Tools\CreateTransferDraftTool::class,
        \App\Services\Ai\Inventory\Tools\UpdateTransferDraftTool::class,
        \App\Services\Ai\Inventory\Tools\ApproveTransferTool::class,
        \App\Services\Ai\Inventory\Tools\ShipTransferTool::class,
        \App\Services\Ai\Inventory\Tools\ReceiveTransferTool::class,
        \App\Services\Ai\Inventory\Tools\CancelTransferTool::class,
        // Phase 4: stocktake + vision
        \App\Services\Ai\Inventory\Tools\CreateStocktakeTool::class,
        \App\Services\Ai\Inventory\Tools\RecordCountsTool::class,
        \App\Services\Ai\Inventory\Tools\StocktakeVarianceTool::class,
        \App\Services\Ai\Inventory\Tools\ApproveStocktakeTool::class,
        \App\Services\Ai\Inventory\Tools\CancelStocktakeTool::class,
        \App\Services\Ai\Inventory\Tools\ParseCountSheetTool::class,
        // Phase 5: proactive + what-if
        \App\Services\Ai\Inventory\Tools\WhatIfProductionCostTool::class,
        \App\Services\Ai\Inventory\Tools\WhatIfTransferCoverageTool::class,
    ];

    public function __construct(private SettingService $settings) {}

    /** @return array<string, AgentTool> */
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
        return $this->settings->getBool('ai_inventory_tools_enabled', false);
    }

    public function isToolHidden(string $name): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        return false;
    }

    public function domain(): string
    {
        return 'inventory';
    }

    public function usesGlobalAdminLock(): bool
    {
        return false;
    }

    public function canReadEntity(User $actor, string $type): ?bool
    {
        if (! in_array($type, ['transfer', 'stocktake', 'production_receipt'], true)) {
            return null;
        }
        if (! $this->enabled()) {
            return false;
        }

        return match ($type) {
            'transfer' => $actor->can('transfers.view'),
            'stocktake' => $actor->can('stocktakes.view'),
            'production_receipt' => $actor->can('inventory.view'),
            default => false,
        };
    }

    public function systemPromptSection(User $actor): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        if (! $actor->can('inventory.view')) {
            return null;
        }

        return implode("\n", [
            'دومين المخزون:',
            '— المتاح = الفعلي − المحجوز − التالف. القيد (in_transit) مش متاح للبيع.',
            '— حالات التحويل: مسودة ← معتمد ← مشحون ← مستلم. المشحون مابيتلغيش.',
            '— وانت بتعد في الجرد، ماتقولش الرصيد المتوقع (system_qty) للموظف.',
            '— ماتقولش أي رقم تكلفة غير اللي جاي من نتيجة أداة.',
            '— الكميات للأصناف المعدودة أعداد صحيحة.',
            '— وحدات: دستة = 12، نص دستة = 6. "كرتونة" بتختلف حسب الصنف فاسأل عنها.',
        ]);
    }
}
