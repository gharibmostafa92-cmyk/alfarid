<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class PlanCatalogFixesTool implements AgentTool
{
    public function __construct(
        private CatalogHealthReportTool $healthReport,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'plan_catalog_fixes';
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
        return ['products.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'بناء خطة إصلاح للكتالوج مرتبة حسب الأثر (1: نشر المسودات الجاهزة، 2: أسعار الجملة الناقصة، 3: الأسعار الشاذة، 4: تعطيل الأقسام الفاضية) مع ترجمة كل بند لأداة مناسبة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'draft_older_than_days' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $reportOutcome = $this->healthReport->handle($actor, $args);
        $report = $reportOutcome->data;

        $steps = [];
        $bulkCandidateItems = [];

        // Priority 1: Ready drafts (Quick wins)
        $readyDrafts = (array) ($report['ready_drafts']['examples'] ?? []);
        $readyCount = (int) ($report['ready_drafts']['count'] ?? 0);
        if ($readyCount > 0) {
            $firstReady = $readyDrafts[0] ?? null;
            $steps[] = [
                'priority' => 1,
                'category' => 'ready_drafts',
                'title' => "نشر المسودات الجاهزة ({$readyCount}) — مكسب فوري",
                'tool' => 'set_product_status',
                'bulk_eligible' => false,
                'suggested_calls' => array_map(fn ($p) => [
                    'tool' => 'set_product_status',
                    'args' => ['product_id' => $p['id'], 'status' => 'published'],
                ], $readyDrafts),
                'prompt' => $firstReady ? "انشر المنتج {$firstReady['code']}" : 'وريني المسودات الجاهزة للنشر',
            ];
        }

        // Priority 2: Missing wholesale/retail prices
        $missingPrices = (array) ($report['missing_prices']['examples'] ?? []);
        $missingPricesCount = (int) ($report['missing_prices']['count'] ?? 0);
        if ($missingPricesCount > 0) {
            $steps[] = [
                'priority' => 2,
                'category' => 'missing_prices',
                'title' => "استكمال الأسعار الناقصة في المتغيرات النشطة ({$missingPricesCount})",
                'tool' => 'update_variant',
                'bulk_eligible' => true,
                'examples' => $missingPrices,
                'prompt' => 'وريني المتغيرات اللي ناقصها أسعار عشان نسعرها',
            ];
        }

        // Priority 3: Price anomalies
        $anomalies = (array) ($report['price_anomalies']['examples'] ?? []);
        $anomaliesCount = (int) ($report['price_anomalies']['count'] ?? 0);
        if ($anomaliesCount > 0) {
            $steps[] = [
                'priority' => 3,
                'category' => 'price_anomalies',
                'title' => "إصلاح الأسعار الشاذة ({$anomaliesCount})",
                'tool' => 'update_variant',
                'bulk_eligible' => true,
                'examples' => $anomalies,
                'prompt' => 'وريني المتغيرات اللي فيها أسعار شاذة',
            ];
        }

        // Priority 4: Empty active categories (deactivate)
        $emptyCategories = array_values(array_filter(
            (array) ($report['empty_categories']['examples'] ?? []),
            fn ($c) => ! empty($c['is_active'])
        ));
        $emptyCount = (int) ($report['empty_categories']['count'] ?? 0);
        if ($emptyCount > 0) {
            foreach ($emptyCategories as $cat) {
                $bulkCandidateItems[] = [
                    'tool' => 'set_category_status',
                    'args' => ['category_id' => $cat['id'], 'is_active' => false],
                ];
            }
            $steps[] = [
                'priority' => 4,
                'category' => 'empty_categories',
                'title' => "تعطيل الأقسام الفاضية ({$emptyCount})",
                'tool' => 'set_category_status',
                'bulk_eligible' => true,
                'suggested_calls' => $bulkCandidateItems,
                'prompt' => 'عطّل الأقسام الفاضية',
            ];
        }

        $nextActions = array_values(array_map(fn ($s) => [
            'label' => $s['title'],
            'prompt' => $s['prompt'],
        ], $steps));

        $uiChecks = array_values(array_map(fn ($s) => [
            'key' => $s['category'],
            'ok' => false,
            'severity' => $s['priority'] <= 2 ? 'blocker' : 'warning',
            'label' => "أولوية {$s['priority']}: {$s['title']}",
            'message' => "الأداة المقترحة: {$s['tool']}",
        ], $steps));

        return $this->presenter->present(
            $actor,
            [
                'steps_count' => count($steps),
                'steps' => $steps,
                'bulk_actions_suggestion' => count($bulkCandidateItems) >= 2 ? [
                    'tool' => 'bulk_actions',
                    'args' => ['items' => $bulkCandidateItems],
                ] : null,
            ],
            [
                'type' => 'checklist',
                'title' => 'خطة إصلاح الكتالوج مرتبة حسب الأثر',
                'verdict' => $steps === [] ? 'ready' : 'warning',
                'checks' => $uiChecks,
                'next_actions' => $nextActions,
            ],
        );
    }
}
