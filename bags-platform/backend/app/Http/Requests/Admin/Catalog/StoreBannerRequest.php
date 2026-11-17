<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Enums\Catalog\BannerLinkType;
use App\Enums\Catalog\BannerPosition;
use App\Models\Banner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('staff') ?? $this->user();

        return $user !== null && $user->can('store.manage');
    }

    protected function isCreate(): bool
    {
        return true;
    }

    protected function linkTypeRule(): mixed
    {
        return Rule::enum(BannerLinkType::class)->except([BannerLinkType::Brand]);
    }

    public function rules(): array
    {
        $type = $this->input('link_type');

        return [
            'title' => ['required', 'string', 'max:120'],
            'image' => [$this->isCreate() ? 'required' : 'sometimes', 'file', 'image',
                'mimes:png,jpg,jpeg,webp,avif', 'max:5120', 'dimensions:min_width=600,min_height=300,max_width=4000,max_height=4000'],
            'position' => ['required', Rule::enum(BannerPosition::class)],
            'link_type' => ['required', $this->linkTypeRule()],
            'link_value' => match ($type) {
                'product' => ['required', 'integer', Rule::exists('products', 'id')->withoutTrashed()],
                'category' => ['required', 'integer', Rule::exists('categories', 'id')->withoutTrashed()],
                'brand' => ['required', 'integer', Rule::exists('brands', 'id')->withoutTrashed()],
                'external' => ['required', 'url:https', 'max:2048'],
                default => ['nullable'],
            },
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => array_filter(['nullable', 'date', $this->filled('starts_at') ? 'after:starts_at' : null]),
            'is_active' => ['required', 'boolean'],
            'kicker' => ['nullable', 'string', 'max:60'],
            'headline' => ['nullable', 'string', 'max:80'],
            'headline_bold' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:240'],
            'cta_label' => ['nullable', 'string', 'max:30'],
            'bg_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['starts_at', 'ends_at']) || ! $this->filled('ends_at')) {
                return;
            }
            if ($this->filled('starts_at') || $this->exists('starts_at')) {
                return;
            }
            $banner = $this->route('banner');
            if (! $banner instanceof Banner || $banner->starts_at === null) {
                return;
            }
            if (CarbonImmutable::parse((string) $this->input('ends_at'))->lte($banner->starts_at)) {
                $validator->errors()->add('ends_at', 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البدء.');
            }
        }];
    }
}
