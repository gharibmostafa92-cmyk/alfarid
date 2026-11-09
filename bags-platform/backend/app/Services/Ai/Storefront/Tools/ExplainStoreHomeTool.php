<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Enums\BannerLinkType;
use App\Models\Banner;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Catalog\BannerLinkResolver;
use App\Support\BannerImage;

final class ExplainStoreHomeTool implements AgentTool
{
    public function __construct(private BannerLinkResolver $links) {}

    public function name(): string
    {
        return 'explain_store_home';
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
        return ['store.manage', 'settings.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'البانرات الظاهرة في الصفحة الرئيسية الآن، بالترتيب، مع الرابط المكسور لو المنتج أو القسم مش متاح.',
            'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $banners = Banner::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')->orderBy('id')->get();
        $this->links->attach($banners);
        $rows = $banners->map(function (Banner $banner): array {
            $href = $banner->getAttribute('resolved_href');
            $expectsTarget = in_array($banner->link_type, [BannerLinkType::Product, BannerLinkType::Category, BannerLinkType::External], true);

            return [
                'id' => $banner->id,
                'title' => $banner->title,
                'position' => $banner->position?->value ?? (string) $banner->position,
                'sort_order' => $banner->sort_order,
                'link_type' => $banner->link_type?->value ?? (string) $banner->link_type,
                'link_value' => $banner->link_value,
                'href' => $href,
                'broken_link' => $expectsTarget && ($href === null || $href === ''),
                'image_missing' => BannerImage::url($banner->image_path) === null,
            ];
        })->values()->all();

        return ToolOutcome::data(['banners' => $rows]);
    }
}
