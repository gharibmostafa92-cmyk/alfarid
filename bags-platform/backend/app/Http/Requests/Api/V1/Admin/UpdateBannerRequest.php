<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\BannerLinkType;
use App\Enums\BannerPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function isCreate(): bool
    {
        return false;
    }

    public function rules(): array
    {
        $type = $this->input('link_type');

        return [
            'title' => ['required', 'string', 'max:120'],
            'image' => [$this->isCreate() ? 'required' : 'sometimes', 'file', 'image',
                'mimes:png,jpg,jpeg,webp', 'max:5120', 'dimensions:min_width=600,min_height=300'],
            'position' => ['required', Rule::enum(BannerPosition::class)],
            'link_type' => ['required', Rule::enum(BannerLinkType::class)],
            'link_value' => match ($type) {
                'product' => ['required', 'integer', Rule::exists('products', 'id')],
                'category' => ['required', 'integer', Rule::exists('categories', 'id')],
                'brand' => ['required', 'integer'],
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
}
