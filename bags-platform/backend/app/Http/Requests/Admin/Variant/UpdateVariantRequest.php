<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Variant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVariantRequest extends FormRequest
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
        $rules = VariantRules::base($this->route('variant')->id);

        foreach ($rules as $field => $rule) {
            array_unshift($rule, 'sometimes');
            $rules[$field] = $rule;
        }

        return $rules;
    }
}
