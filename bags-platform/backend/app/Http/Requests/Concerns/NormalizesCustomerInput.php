<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Customer;

/**
 * Standardizes customer identity attributes prior to request validation.
 */
trait NormalizesCustomerInput
{
    /**
     * Normalize authentication identifiers before standard validation rules run.
     *
     * Canonical phone digits and lowercase emails prevent duplicate accounts caused by formatting variations.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['email', 'phone', 'login'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = match ($field) {
                    'phone' => Customer::normalizePhone($value),
                    'login' => Customer::normalizeIdentifier($value) ?? '',
                    default => mb_strtolower(trim($value)),
                };
            }
        }
        $this->merge($normalized);
    }
}
