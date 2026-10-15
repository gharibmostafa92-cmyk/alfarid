<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadProductMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        return [
            'collection' => ['required', Rule::in(['images', 'video'])],
            'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $this->route('product')->id)->whereNull('deleted_at')],
            'files' => ['required_if:collection,images', 'array', 'max:10'],
            'files.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'file' => ['required_if:collection,video', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200'],
        ];
    }
}
