<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

trait NormalizesCustomerInput
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['email', 'phone', 'login'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = $field === 'phone' ? trim($value) : mb_strtolower(trim($value));
            }
        }
        $this->merge($normalized);
    }
}
