<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Http\Requests\Admin\Concerns\LimitsArrayInput;
use App\Services\Catalog\ProductMediaService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderProductMediaRequest extends FormRequest
{
    use LimitsArrayInput;

    protected function arrayLimits(): array
    {
        return ['ids' => ProductMediaService::MAX_IMAGES_PER_OWNER];
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
            'ids' => ['required', 'array', 'min:1', 'max:'.ProductMediaService::MAX_IMAGES_PER_OWNER],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
