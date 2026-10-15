<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueCatalogIdentifier implements ValidationRule
{
    public function __construct(private string $table, private string $column, private ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        $exists = DB::table($this->table)->whereRaw('LOWER('.$this->column.') = ?', [strtolower($value)])
            ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))->exists();
        if ($exists) {
            $fail('القيمة مستخدمة بالفعل');
        }
    }
}
