<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Http\Requests\Admin\Concerns\LimitsArrayInput;
use App\Rules\MaxImagePixels;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadProductMediaRequest extends FormRequest
{
    use LimitsArrayInput;

    protected function arrayLimits(): array
    {
        return ['files' => 4];
    }

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
            'files' => ['required_if:collection,images', 'array', 'max:4'],
            // Bound decoded memory as well as compressed size for synchronous GD conversions.
            'files.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120', new MaxImagePixels],
            'client_upload_ids' => ['nullable', 'array', 'max:4', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && count($value) !== count($this->file('files', []))) {
                    $fail('يجب إرسال معرف رفع لكل صورة.');
                }
            }],
            'client_upload_ids.*' => ['uuid', 'distinct'],
            'file' => ['required_if:collection,video', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'extensions:mp4,webm,mov', 'max:51200'],
        ];
    }
}
