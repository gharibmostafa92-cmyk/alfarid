<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\ToolProvider;
use App\Services\SettingService;

/**
 * Pricing AI domain (P07): price-list, coupon and promotion tools.
 *
 * Disabled by default behind ai_pricing_tools_enabled. Tool classes land
 * per phase under Tools/ and are registered in TOOL_CLASSES below.
 */
final class PricingToolProvider implements ToolProvider
{
    /** @var array<string, AgentTool>|null */
    private ?array $toolsCache = null;

    /** @var list<class-string<AgentTool>> */
    private const TOOL_CLASSES = [
        // Phase 1: Read tools
        \App\Services\Ai\Pricing\Tools\ExplainPriceTool::class,
        \App\Services\Ai\Pricing\Tools\QuotePriceTool::class,
        \App\Services\Ai\Pricing\Tools\CompareCustomerPricesTool::class,
        \App\Services\Ai\Pricing\Tools\ListPriceListsTool::class,
        \App\Services\Ai\Pricing\Tools\GetPriceListTool::class,
        \App\Services\Ai\Pricing\Tools\ListCouponsTool::class,
        \App\Services\Ai\Pricing\Tools\GetCouponTool::class,
        \App\Services\Ai\Pricing\Tools\ListPromotionsTool::class,
        \App\Services\Ai\Pricing\Tools\PricingHealthReportTool::class,
        \App\Services\Ai\Pricing\Tools\SimulateCartTool::class,

        // Phase 2: Price-list write tools
        \App\Services\Ai\Pricing\Tools\CreatePriceListTool::class,
        \App\Services\Ai\Pricing\Tools\UpdatePriceListTool::class,
        \App\Services\Ai\Pricing\Tools\SetPriceListStatusTool::class,
        \App\Services\Ai\Pricing\Tools\UpsertPriceListItemsTool::class,
        \App\Services\Ai\Pricing\Tools\DeletePriceListItemsTool::class,
        \App\Services\Ai\Pricing\Tools\ClonePriceListTool::class,
        \App\Services\Ai\Pricing\Tools\SchedulePriceChangeTool::class,
        \App\Services\Ai\Pricing\Tools\RefreshCatalogPricesTool::class,

        // Phase 4: Vision
        \App\Services\Ai\Pricing\Tools\ParsePriceSheetTool::class,

        // Phase 4: Import (CSV → single upsert proposal)
        \App\Services\Ai\Pricing\Tools\ImportPriceListItemsTool::class,

        // Phase 3: Coupon & promotion write tools
        \App\Services\Ai\Pricing\Tools\CreateCouponsTool::class,
        \App\Services\Ai\Pricing\Tools\UpdateCouponTool::class,
        \App\Services\Ai\Pricing\Tools\SetCouponStatusTool::class,
        \App\Services\Ai\Pricing\Tools\CreatePromotionTool::class,
        \App\Services\Ai\Pricing\Tools\UpdatePromotionTool::class,
        \App\Services\Ai\Pricing\Tools\SetPromotionStatusTool::class,
        // Phase 1: Read tools (explain_price, quote_price, ...).
        // Phase 2: Price-list write tools (upsert_price_list_items, ...).
        // Phase 3: Coupon & promotion tools (create_coupons, ...).
        // Phase 4: Vision/import tools (parse_price_sheet, ...).
        // Phase 5: What-if & proactive tools (what_if_price_change, ...).
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
        return $this->settings->getBool('ai_pricing_tools_enabled', false);
    }

    public function domain(): string
    {
        return 'pricing';
    }

    public function usesGlobalAdminLock(): bool
    {
        return false;
    }

    public function canReadEntity(User $actor, string $type): ?bool
    {
        if (! in_array($type, ['price_list', 'coupon', 'promotion'], true)) {
            return null;
        }
        if (! $this->enabled()) {
            return false;
        }

        return match ($type) {
            'price_list' => $actor->can('price_lists.view'),
            'coupon' => $actor->can('coupons.view'),
            'promotion' => $actor->can('promotions.view'),
            default => false,
        };
    }

    public function systemPromptSection(User $actor): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        if (! $actor->can('price_lists.view') && ! $actor->can('coupons.view')) {
            return null;
        }

        return implode("\n", [
            'دومين التسعير:',
            '— ترتيب السعر: قائمة العميل الخاصة ← قائمة المجموعة ← قائمة القناة (جملة/موزع/قطاعي) ← السعر الأساسي للمتغير.',
            '— خصم المجموعة بيتطبق على سعر القناة أو الأساسي بس، مش على قوائم العميل أو المجموعة.',
            '— عميل الجملة أو الموزع اللي مش معتمد بيتسعّر قطاعي.',
            '— لأي سؤال "ليه السعر كده" استخدم explain_price. ماتحسبش أسعار بنفسك أبدًا.',
            '— العروض (promotions) بتتسجل بس ومش بتتطبق تلقائيًا على السلة حاليًا. قول كده بوضوح.',
            '— الكوبون في الحساب بيخصم من البضاعة. كوبون free_shipping خصمه على الشحن مش البضاعة.',
            '— التواريخ النسبية ("من أول الشهر"، "لحد الخميس") تتحسب بتوقيت القاهرة.',
            '— ماتعرضش سعر تكلفة إلا لو الأداة رجّعته.',
        ]);
    }
}
