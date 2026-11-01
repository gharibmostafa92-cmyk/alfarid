<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

final class UniqueCustomerEmail implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }
        $exists = DB::table('customers')->whereNull('deleted_at')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($value))])
            ->when($this->ignoreId, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->exists();
        if ($exists) {
            $fail('هذا البريد مستخدم بحساب آخر في النظام.');
        }
    }
}
