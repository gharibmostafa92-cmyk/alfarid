<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Enums\Catalog\BannerLinkType;
use App\Enums\Catalog\BannerPosition;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Banner;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Auth\StaffAdministration;
use App\Services\Catalog\BannerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpdateBannerTool implements LocksTargets, WriteTool
{
    public function __construct(private ProposalBuilder $proposals, private BannerService $banners) {}

    public function name(): string
    {
        return 'update_banner';
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
        return ['store.manage'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تعديل نص بانر أو رابطه أو موضعه من غير تغيير الصورة. يحتاج تأكيد.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['banner_id'],
                'properties' => (object) [
                    'banner_id' => ['type' => 'INTEGER'],
                    'title' => ['type' => 'STRING'],
                    'position' => ['type' => 'STRING', 'enum' => ['main_slider', 'middle_banner', 'popup']],
                    'link_type' => ['type' => 'STRING', 'enum' => ['product', 'category', 'external', 'none']],
                    'link_value' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $banner = Banner::query()->find((int) ($args['banner_id'] ?? 0));
        if (! $banner) {
            return ToolOutcome::denied('البانر غير موجود.', auditable: false);
        }
        $data = [
            'title' => trim((string) ($args['title'] ?? $banner->title)),
            'position' => (string) ($args['position'] ?? $banner->position?->value),
            'link_type' => (string) ($args['link_type'] ?? $banner->link_type?->value),
            'link_value' => array_key_exists('link_value', $args) ? $args['link_value'] : $banner->link_value,
        ];
        Validator::make($data, [
            'title' => ['required', 'string', 'max:120'],
            'position' => ['required', Rule::enum(BannerPosition::class)],
            'link_type' => ['required', Rule::enum(BannerLinkType::class)->except([BannerLinkType::Brand])],
        ])->validate();
        BannerLinks::validateTarget($data);

        $before = ['title' => $banner->title, 'position' => $banner->position?->value, 'link_type' => $banner->link_type?->value, 'link_value' => $banner->link_value];

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data + ['banner_id' => $banner->id],
            risk: $this->risk(),
            title: 'تعديل البانر: '.$banner->title,
            before: $before,
            after: $data,
            expectedVersions: ['banner:'.$banner->id => (string) $banner->updated_at?->format('Y-m-d H:i:s.u')],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        Banner::query()->whereKey((int) ($action->args['banner_id'] ?? 0))->lockForUpdate()->first();
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        BannerLinks::validateTarget($action->args);
        $updated = DB::transaction(function () use ($action): Banner {
            $banner = Banner::query()->lockForUpdate()->findOrFail((int) $action->args['banner_id']);
            $expected = $action->expected_versions['banner:'.$banner->id] ?? null;
            if ($expected !== (string) $banner->updated_at?->format('Y-m-d H:i:s.u')) {
                throw new BusinessException('تم تعديل البانر من شاشة أخرى أثناء المراجعة؛ اطلب الاقتراح من جديد.', [], 409);
            }

            return $this->banners->update($banner, $action->args, null);
        });

        StaffAdministration::audit(
            $actor,
            'banner.updated',
            'banner',
            $updated->id,
            ['title', 'position', 'link_type', 'link_value'],
            $action->preview['before'] ?? null,
            $action->preview['after'] ?? null,
            ['channel' => 'ai', 'tool' => $this->name(), 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم تعديل البانر.', ['banner_id' => $updated->id, 'title' => $updated->title]);
    }
}
