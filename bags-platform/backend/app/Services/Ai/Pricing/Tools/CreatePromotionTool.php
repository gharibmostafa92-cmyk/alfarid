<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Http\Resources\Admin\PromotionResource;
use App\Models\AiPendingAction;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\StaffAdministration;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class CreatePromotionTool implements WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'create_promotion';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'medium';
    }

    public function permissions(): array
    {
        return ['promotions.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء عرض ترويجي (النطاقات تُحل من الأسماء: أقسام/متغيرات/ماركات). ملحوظة ثابتة: العرض بيتسجل بس ومش بيتطبق تلقائيًا على السلة حاليًا. النوع buy_x_get_y ممنوع لحد ما يتصلح.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['name', 'type'],
                'properties' => (object) [
                    'name' => ['type' => 'STRING'],
                    'type' => ['type' => 'STRING', 'enum' => ['percentage_discount', 'fixed_discount', 'free_shipping']],
                    'value' => ['type' => 'NUMBER'],
                    'min_order_amount' => ['type' => 'NUMBER'],
                    'targets' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT']],
                    'starts_at' => ['type' => 'STRING'],
                    'ends_at' => ['type' => 'STRING'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $data = $this->validate($actor, $args);

        $notice = self::autoAppliedNotice();

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data,
            risk: $this->risk(),
            title: 'إنشاء عرض: '.$data['name'],
            before: ['promotions_count' => 0],
            after: ['name' => $data['name'], 'type' => $data['type']],
            warnings: $notice !== null ? [$notice] : [],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $promotion = Promotion::query()->create((array) $action->args);
        $notice = self::autoAppliedNotice($promotion);

        StaffAdministration::audit(
            $actor,
            'promotion.created',
            'promotion',
            $promotion->id,
            ['name', 'type'],
            [],
            ['name' => $promotion->name, 'type' => $promotion->type],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'promotion:'.$promotion->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم إنشاء العرض.'.($notice !== null ? ' '.$notice : ''), [
            'promotion_id' => $promotion->id,
            'auto_applied' => $notice === null,
        ]);
    }

    public static function autoAppliedNotice(?Promotion $promotion = null): ?string
    {
        $probe = $promotion ?? new Promotion;
        try {
            $autoApplied = (bool) ((new PromotionResource($probe))->toArray(request())['auto_applied'] ?? false);
        } catch (\Throwable) {
            $autoApplied = false;
        }

        return $autoApplied ? null : 'العرض اتسجل لكنه مش هيتطبق تلقائيًا على السلة لحد ما محرك العروض يتبني.';
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public function validate(User $actor, array $args): array
    {
        if (isset($args['type']) && $args['type'] === 'buy_x_get_y') {
            throw ValidationException::withMessages(['type' => 'النوع buy_x_get_y ممنوع حاليًا لحد ما يتصلح.']);
        }
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'اسم العرض مطلوب.']);
        }
        $type = (string) ($args['type'] ?? '');
        if (! in_array($type, ['percentage_discount', 'fixed_discount', 'free_shipping'], true)) {
            throw ValidationException::withMessages(['type' => 'نوع العرض غير صالح.']);
        }
        $value = null;
        if ($type !== 'free_shipping') {
            if (! isset($args['value']) || ! is_numeric($args['value']) || (float) $args['value'] < 0) {
                throw ValidationException::withMessages(['value' => 'قيمة العرض مطلوبة ورقم موجب.']);
            }
            if ($type === 'percentage_discount' && (float) $args['value'] > 100) {
                throw ValidationException::withMessages(['value' => 'نسبة الخصم لا تتجاوز 100%.']);
            }
            $value = (float) $args['value'];
        }
        $minOrder = null;
        if (isset($args['min_order_amount'])) {
            if (! is_numeric($args['min_order_amount']) || (float) $args['min_order_amount'] < 0) {
                throw ValidationException::withMessages(['min_order_amount' => 'لازم يكون رقم موجب.']);
            }
            $minOrder = (float) $args['min_order_amount'];
        }

        [$appliesTo, $targetIds] = self::resolveTargets($this->resolver, $actor, (array) ($args['targets'] ?? []));

        $startsAt = $this->optionalDate($args['starts_at'] ?? null);
        $endsAt = $this->optionalDate($args['ends_at'] ?? null);
        if ($startsAt === false || $endsAt === false) {
            throw ValidationException::withMessages(['starts_at' => 'صيغة التاريخ غير صالحة.']);
        }
        if ($startsAt !== null && $endsAt !== null && $endsAt->lt($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'نهاية الصلاحية يجب ألا تسبق بدايتها.']);
        }

        return [
            'name' => $name,
            'type' => $type,
            'value' => $value ?? 0,
            'min_order_amount' => $minOrder,
            'applies_to' => $appliesTo,
            'target_ids' => $targetIds === [] ? null : $targetIds,
            'starts_at' => $startsAt?->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt?->format('Y-m-d H:i:s'),
            'is_active' => array_key_exists('is_active', $args) ? (bool) $args['is_active'] : true,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $targets
     * @return array{0: string, 1: list<int>|null}
     */
    public static function resolveTargets(EntityResolver $resolver, User $actor, array $targets): array
    {
        if ($targets === []) {
            return ['all', null];
        }
        $ids = [];
        $kinds = [];
        foreach ($targets as $index => $target) {
            if (! is_array($target) || empty($target['query'])) {
                throw ValidationException::withMessages(['targets' => 'الهدف رقم '.($index + 1).' محتاج kind وquery.']);
            }
            $kind = (string) ($target['kind'] ?? '');
            if (! in_array($kind, ['category', 'variant', 'brand'], true)) {
                throw ValidationException::withMessages(['targets' => 'نوع الهدف لازم يكون category أو variant أو brand.']);
            }
            $kinds[] = $kind;
            $options = match ($kind) {
                'category' => $resolver->categories($actor, (string) $target['query']),
                'variant' => $resolver->variants($actor, (string) $target['query']),
                'brand' => $resolver->brands((string) $target['query']),
            };
            if (count($options) !== 1) {
                throw ValidationException::withMessages(['targets' => "الهدف «{$target['query']}» غامض — حدده بدقة أكبر."]);
            }
            $ids[] = (int) $options[0]['id'];
        }
        if (count(array_unique($kinds)) > 1) {
            throw ValidationException::withMessages(['targets' => 'العرض الواحد يدعم نطاقًا واحدًا (أقسام أو متغيرات أو ماركات).']);
        }
        $appliesTo = match ($kinds[0]) {
            'category' => 'categories',
            'variant' => 'variants',
            'brand' => 'brands',
        };

        return [$appliesTo, array_values(array_unique($ids))];
    }

    private function optionalDate(mixed $value): Carbon|bool|null
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return Carbon::parse(trim((string) $value));
        } catch (\Throwable) {
            return false;
        }
    }
}
