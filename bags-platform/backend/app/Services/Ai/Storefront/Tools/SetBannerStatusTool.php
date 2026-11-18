<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

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

final class SetBannerStatusTool implements LocksTargets, WriteTool
{
    public function __construct(private ProposalBuilder $proposals, private BannerService $banners) {}

    public function name(): string
    {
        return 'set_banner_status';
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
            'description' => 'تفعيل أو إيقاف بانر. يحتاج تأكيد.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['banner_id', 'is_active'],
                'properties' => (object) [
                    'banner_id' => ['type' => 'INTEGER'],
                    'is_active' => ['type' => 'BOOLEAN'],
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
        if (! array_key_exists('is_active', $args)) {
            return ToolOutcome::denied('حدد is_active.', auditable: false);
        }
        $wanted = (bool) $args['is_active'];
        if ((bool) $banner->is_active === $wanted) {
            return ToolOutcome::denied('البانر بالفعل '.($wanted ? 'ظاهر.' : 'مخفي.'), auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['banner_id' => $banner->id, 'is_active' => $wanted],
            risk: $this->risk(),
            title: ($wanted ? 'إظهار' : 'إخفاء').' البانر: '.$banner->title,
            before: ['is_active' => (bool) $banner->is_active],
            after: ['is_active' => $wanted],
            expectedVersions: ['banner:'.$banner->id => (string) $banner->updated_at?->format('Y-m-d H:i:s.u')],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        Banner::query()->whereKey((int) ($action->args['banner_id'] ?? 0))->lockForUpdate()->first();
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $updated = DB::transaction(function () use ($action): Banner {
            $banner = Banner::query()->lockForUpdate()->findOrFail((int) $action->args['banner_id']);
            $expected = $action->expected_versions['banner:'.$banner->id] ?? null;
            if ($expected !== (string) $banner->updated_at?->format('Y-m-d H:i:s.u')) {
                throw new BusinessException('تم تعديل البانر من شاشة أخرى أثناء المراجعة؛ اطلب الاقتراح من جديد.', [], 409);
            }

            return $this->banners->setActive($banner, (bool) $action->args['is_active']);
        });

        StaffAdministration::audit(
            $actor,
            'banner.status_updated',
            'banner',
            $updated->id,
            ['is_active'],
            $action->preview['before'] ?? null,
            ['is_active' => (bool) $updated->is_active],
            ['channel' => 'ai', 'tool' => $this->name(), 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم تحديث ظهور البانر.', ['banner_id' => $updated->id, 'is_active' => (bool) $updated->is_active]);
    }
}
