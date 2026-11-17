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
        foreach (['email', 'phone', 'login', 'recipient_phone', 'whatsapp_phone'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $this->request->remove($field);
                $this->query->remove($field);
                $this->offsetUnset($field);

                continue;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                $canonical = match ($field) {
                    'phone', 'recipient_phone', 'whatsapp_phone' => Customer::normalizePhone($trimmed),
                    'login' => Customer::normalizeIdentifier($trimmed) ?? '',
                    default => mb_strtolower($trimmed),
                };
                if ($canonical === '') {
                    $this->request->remove($field);
                    $this->query->remove($field);
                    $this->offsetUnset($field);

                    continue;
                }
                $normalized[$field] = $canonical;
            }
        }
        $this->merge($normalized);
    }
}
