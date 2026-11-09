<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Enums\BannerLinkType;
use App\Enums\BannerPosition;
use App\Models\AiPendingAction;
use App\Models\Banner;
use App\Models\User;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\BannerService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpdateBannerTool implements WriteTool
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

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data + ['banner_id' => $banner->id],
            risk: $this->risk(),
            title: 'تعديل البانر: '.$banner->title,
            before: ['title' => $banner->title, 'position' => $banner->position?->value, 'link_type' => $banner->link_type?->value, 'link_value' => $banner->link_value],
            after: $data,
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        BannerLinks::validateTarget($action->args);
        $banner = Banner::query()->findOrFail((int) $action->args['banner_id']);
        $updated = $this->banners->update($banner, $action->args, null);

        return ToolOutcome::result(true, 'تم تعديل البانر.', ['banner_id' => $updated->id, 'title' => $updated->title]);
    }
}
