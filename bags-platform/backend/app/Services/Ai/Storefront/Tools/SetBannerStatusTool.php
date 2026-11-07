<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Models\AiPendingAction;
use App\Models\Banner;
use App\Models\User;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\BannerService;

final class SetBannerStatusTool implements WriteTool
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
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $banner = Banner::query()->findOrFail((int) $action->args['banner_id']);
        $updated = $this->banners->setActive($banner, (bool) $action->args['is_active']);

        return ToolOutcome::result(true, 'تم تحديث ظهور البانر.', ['banner_id' => $updated->id, 'is_active' => (bool) $updated->is_active]);
    }
}
