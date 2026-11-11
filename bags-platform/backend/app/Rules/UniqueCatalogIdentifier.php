<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueCatalogIdentifier implements ValidationRule
{
    public function __construct(private string $table, private string $column, private ?int $ignoreId = null)
    {
        // L7: table/column interpolate into raw SQL — reject anything that is
        // not a plain identifier instead of trusting every caller.
        foreach (['table' => $table, 'column' => $column] as $kind => $identifier) {
            if (preg_match('/\A[a-z_][a-z0-9_]*\z/', $identifier) !== 1) {
                throw new \InvalidArgumentException("Invalid catalog {$kind} identifier [{$identifier}].");
            }
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        // PostgreSQL LOWER() is Unicode-aware; ASCII-only strtolower() would miss
        // duplicates such as "SKU-É" vs "sku-é".
        $exists = DB::table($this->table)->whereRaw('LOWER('.$this->column.') = ?', [mb_strtolower(trim($value))])
            ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))->exists();
        if ($exists) {
            $fail('القيمة مستخدمة بالفعل');
        }
    }
}
