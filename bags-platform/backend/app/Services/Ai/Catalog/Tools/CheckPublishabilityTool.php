<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Catalog\ProductPublishabilityChecker;

final class CheckPublishabilityTool implements AgentTool
{
    public function __construct(
        private ProductPublishabilityChecker $checker,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'check_publishability';
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
            'description' => 'فحص جاهزية منتج للنشر وإرجاع قائمة الشروط والنواقص وخطوات الإصلاح السريعة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
                    'code' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $product = null;
        if (! empty($args['product_id'])) {
            $product = Product::with(['category', 'variants', 'media'])->find((int) $args['product_id']);
        } elseif (! empty($args['code'])) {
            $product = Product::with(['category', 'variants', 'media'])
                ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $args['code'])])
                ->first();
        }

        if (! $product) {
            return ToolOutcome::denied('المنتج مش موجود.', auditable: false);
        }

        $checks = $this->checker->evaluate($product);
        $nextActions = self::buildNextActions($product, $checks);
        $ready = ! collect($checks)->contains(fn ($c) => ! $c['ok']);
        $verdict = $ready ? 'ready' : 'blocked';

        $uiChecks = array_map(fn ($c) => [
            'key' => $c['key'],
            'ok' => $c['ok'],
            'label' => $c['label'],
            'hint' => $c['hint'],
            'severity' => 'blocker',
            'message' => $c['label'].($c['hint'] ? ' — '.$c['hint'] : ''),
        ], $checks);

        return $this->presenter->present(
            $actor,
            [
                'product_id' => $product->id,
                'code' => $product->code,
                'status' => $product->status->value,
                'ready' => $ready,
                'verdict' => $verdict,
                'checks' => $checks,
                'next_actions' => $nextActions,
                'deep_link' => '#/products/'.$product->id,
            ],
            [
                'type' => 'checklist',
                'product_id' => $product->id,
                'code' => $product->code,
                'verdict' => $verdict,
                'checks' => $uiChecks,
                'next_actions' => $nextActions,
                'deep_link' => '#/products/'.$product->id,
            ]
        );
    }

    /**
     * @param  list<array{key:string, ok:bool, label:string, hint:?string}>  $checks
     * @return list<array{label:string, prompt:string}>
     */
    public static function buildNextActions(Product $product, array $checks): array
    {
        $code = $product->code ?: ('#'.$product->id);
        $actions = [];
        foreach ($checks as $check) {
            if ($check['ok']) {
                continue;
            }
            if ($check['key'] === 'active_variant') {
                $actions[] = ['label' => 'ضف متغير نشط', 'prompt' => "ضيف متغير أسود للمنتج {$code}"];
            } elseif ($check['key'] === 'retail_price') {
                $actions[] = ['label' => 'حط سعر قطاعي لكل المتغيرات', 'prompt' => "حط سعر القطاعي 850 لكل متغيرات {$code}"];
            } elseif ($check['key'] === 'wholesale_price') {
                $actions[] = ['label' => 'حط سعر جملة 600 لكل المتغيرات', 'prompt' => "حط سعر الجملة 600 لكل متغيرات {$code}"];
            } elseif ($check['key'] === 'image') {
                $actions[] = ['label' => 'ارفع صور', 'prompt' => "هبعتلك صور المنتج {$code}"];
            } elseif ($check['key'] === 'category_path') {
                $catName = $product->category?->name_ar ?? 'القسم';
                $actions[] = ['label' => "فعّل قسم {$catName}", 'prompt' => "فعّل قسم {$catName}"];
            }
        }
        if ($actions === [] && $product->status->value !== 'published') {
            $actions[] = ['label' => "انشر {$code}", 'prompt' => "انشر المنتج {$code}"];
        }

        return $actions;
    }
}
