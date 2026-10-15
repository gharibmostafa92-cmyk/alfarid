<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Validation\ValidationException;

trait LimitsArrayInput
{
    use NormalizesCatalogInput;

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Reject before Laravel expands wildcard rules and runs per-item queries.
        foreach ($this->arrayLimits() as $field => $limit) {
            $value = $this->input($field) ?? $this->file($field);
            if (is_array($value) && count($value) > $limit) {
                throw ValidationException::withMessages([$field => "الحد الأقصى لعدد العناصر هو {$limit}"]);
            }
        }
        $this->normalizeCatalogIdentifiers();
    }

    abstract protected function arrayLimits(): array;
}
