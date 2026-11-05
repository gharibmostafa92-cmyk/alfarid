<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\ToolProvider;
use App\Services\Ai\Inventory\Tools\AdjustStockTool;
use App\Services\Ai\Inventory\Tools\ApproveStocktakeTool;
use App\Services\Ai\Inventory\Tools\ApproveTransferTool;
use App\Services\Ai\Inventory\Tools\CancelStocktakeTool;
use App\Services\Ai\Inventory\Tools\CancelTransferTool;
use App\Services\Ai\Inventory\Tools\CreateStocktakeTool;
use App\Services\Ai\Inventory\Tools\CreateTransferDraftTool;
use App\Services\Ai\Inventory\Tools\DeadStockTool;
use App\Services\Ai\Inventory\Tools\ExplainBalanceTool;
use App\Services\Ai\Inventory\Tools\GetStockLevelsTool;
use App\Services\Ai\Inventory\Tools\GetStocktakeTool;
use App\Services\Ai\Inventory\Tools\GetTransferTool;
use App\Services\Ai\Inventory\Tools\InventoryHealthTool;
use App\Services\Ai\Inventory\Tools\InventoryValuationTool;
use App\Services\Ai\Inventory\Tools\ListMovementsTool;
use App\Services\Ai\Inventory\Tools\ListProductionReceiptsTool;
use App\Services\Ai\Inventory\Tools\ListStocktakesTool;
use App\Services\Ai\Inventory\Tools\ListTransfersTool;
use App\Services\Ai\Inventory\Tools\LowStockListTool;
use App\Services\Ai\Inventory\Tools\ParseCountSheetTool;
use App\Services\Ai\Inventory\Tools\ReceiveProductionTool;
use App\Services\Ai\Inventory\Tools\ReceiveTransferTool;
use App\Services\Ai\Inventory\Tools\RecordCountsTool;
use App\Services\Ai\Inventory\Tools\ShipTransferTool;
use App\Services\Ai\Inventory\Tools\StockMatrixTool;
use App\Services\Ai\Inventory\Tools\StocktakeVarianceTool;
use App\Services\Ai\Inventory\Tools\UpdateTransferDraftTool;
use App\Services\Ai\Inventory\Tools\WhatIfProductionCostTool;
use App\Services\Ai\Inventory\Tools\WhatIfTransferCoverageTool;
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
        GetStockLevelsTool::class,
        StockMatrixTool::class,
        ListMovementsTool::class,
        ExplainBalanceTool::class,
        LowStockListTool::class,
        DeadStockTool::class,
        InventoryValuationTool::class,
        ListTransfersTool::class,
        GetTransferTool::class,
        ListStocktakesTool::class,
        GetStocktakeTool::class,
        ListProductionReceiptsTool::class,
        InventoryHealthTool::class,
        // Phase 2: write tools
        AdjustStockTool::class,
        ReceiveProductionTool::class,
        // Phase 3: transfer lifecycle
        CreateTransferDraftTool::class,
        UpdateTransferDraftTool::class,
        ApproveTransferTool::class,
        ShipTransferTool::class,
        ReceiveTransferTool::class,
        CancelTransferTool::class,
        // Phase 4: stocktake + vision
        CreateStocktakeTool::class,
        RecordCountsTool::class,
        StocktakeVarianceTool::class,
        ApproveStocktakeTool::class,
        CancelStocktakeTool::class,
        ParseCountSheetTool::class,
        // Phase 5: proactive + what-if
        WhatIfProductionCostTool::class,
        WhatIfTransferCoverageTool::class,
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
        if ($type === 'variant' && $this->enabled()) {
            return $actor->can('inventory.view') || $actor->can('stocktakes.update');
        }
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
            '— "اخصم بسبب تالف" تسوية decrease بسبب تالف؛ "سجلهم تالف" mark_damaged؛ "اشطب التالف" write_off_damaged.',
            '— سؤال ماذا لو عن التكلفة أو التحويل يحتاج صنفًا محددًا؛ لو مفيش صنف أو selection اسأل عنه قبل الحساب.',
            '— وحدات: دستة = 12، نص دستة = 6. "كرتونة" بتختلف حسب الصنف فاسأل عنها.',
        ]);
    }
}
