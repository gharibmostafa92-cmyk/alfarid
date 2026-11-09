<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Enums\BannerLinkType;
use App\Models\Banner;
use Illuminate\Validation\Rule;

class UpdateBannerRequest extends StoreBannerRequest
{
    protected function isCreate(): bool
    {
        return false;
    }

    protected function linkTypeRule(): mixed
    {
        $banner = $this->route('banner');
        $rule = Rule::enum(BannerLinkType::class);
        if (! $banner instanceof Banner || $banner->link_type !== BannerLinkType::Brand) {
            $rule->except([BannerLinkType::Brand]);
        }

        return $rule;
    }
}

