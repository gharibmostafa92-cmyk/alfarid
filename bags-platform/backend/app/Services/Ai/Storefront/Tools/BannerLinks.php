<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Enums\BannerLinkType;
use App\Models\Banner;
use App\Services\Catalog\BannerLinkResolver;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class BannerLinks
{
    public static function validateTarget(array $data): void
    {
        Validator::make($data, [
            'link_value' => self::valueRules($data['link_type']),
        ])->after(function ($validator) use ($data): void {
            if ($validator->errors()->isNotEmpty() || ! in_array($data['link_type'], ['product', 'category'], true)) {
                return;
            }

            $banner = new Banner([
                'link_type' => $data['link_type'],
                'link_value' => $data['link_value'],
            ]);
            app(BannerLinkResolver::class)->attach(collect([$banner]));
            if ($banner->getAttribute('resolved_href') === null) {
                $validator->errors()->add('link_value', 'هدف الرابط مش متاح في المتجر.');
            }
        })->validate();
    }

    /** @return array<int, mixed> */
    public static function valueRules(string $type): array
    {
        return match ($type) {
            'product' => ['required', 'integer', Rule::exists('products', 'id')->withoutTrashed()],
            'category' => ['required', 'integer', Rule::exists('categories', 'id')->withoutTrashed()],
            BannerLinkType::External->value => ['required', 'url:https', 'max:2048'],
            default => ['nullable'],
        };
    }
}
