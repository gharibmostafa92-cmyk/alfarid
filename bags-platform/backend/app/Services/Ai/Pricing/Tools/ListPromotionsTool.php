<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Models\Promotion;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Shared\SearchTerm;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

// Note: promotions are recorded only and never auto-applied at checkout (M12:
// PromotionResource::auto_applied is always false). Every row below carries
// that badge so the model states it explicitly.
final class ListPromotionsTool implements AgentTool
{
    public function __construct(private PricingPresenter $presenter) {}

    public function name(): string
    {
        return 'list_promotions';
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
        return ['promotions.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'اعرض العروض الترويجية. ملحوظة ثابتة: العروض بتتسجل بس ومش بتتطبق تلقائيًا على السلة حاليًا — قول كده بوضوح.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'query' => ['type' => 'STRING'],
                    'type' => ['type' => 'STRING'],
                    'active' => ['type' => 'BOOLEAN'],
                    'limit' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $query = trim((string) ($args['query'] ?? ''));

        $promotions = Promotion::query()
            ->when($query !== '', fn ($q) => $q->where('name', 'ilike', SearchTerm::pattern($query)))
            ->when(! empty($args['type']), fn ($q) => $q->where('type', $args['type']))
            ->when(array_key_exists('active', $args), fn ($q) => $q->where('is_active', (bool) $args['active']))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $rows = [];
        $data = [];
        foreach ($promotions as $promotion) {
            $rows[] = [
                (string) $promotion->id,
                $promotion->name,
                $promotion->type,
                $promotion->min_order_amount !== null ? (string) $promotion->min_order_amount : '—',
                $promotion->is_active ? 'نشط' : 'متوقف',
                'غير مُفعَّل تلقائيًا',
            ];
            $data[] = [
                'id' => $promotion->id,
                'name' => $this->presenter->untrusted($promotion->name, 150),
                'type' => $promotion->type,
                'value' => $promotion->value,
                'min_order_amount' => $promotion->min_order_amount,
                'applies_to' => $promotion->applies_to,
                'target_ids' => $promotion->target_ids,
                'starts_at' => $promotion->starts_at?->toIso8601String(),
                'ends_at' => $promotion->ends_at?->toIso8601String(),
                'is_active' => (bool) $promotion->is_active,
                'auto_applied' => false,
            ];
        }

        return $this->presenter->present($actor, [
            'promotions' => $data,
            'count' => count($data),
            'notice' => 'العروض بتتسجل بس ومش بتتطبق تلقائيًا على السلة حاليًا.',
        ], $this->presenter->table(
            ['الرقم', 'الاسم', 'النوع', 'الحد الأدنى', 'الحالة', 'التطبيق'],
            $rows
        ));
    }
}
