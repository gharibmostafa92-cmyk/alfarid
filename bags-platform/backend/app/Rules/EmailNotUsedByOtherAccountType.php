<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\Customer\CustomerType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Prevents email collisions across distinct account realms (users, supplier_users, customers).
 */
final class EmailNotUsedByOtherAccountType implements ValidationRule
{
    /**
     * @param  bool  $conceal  Hide which account realm owns the address on public signup paths.
     */
    public function __construct(private readonly string $ownTable, private readonly bool $conceal = false) {}

    /**
     * Validates that the submitted email is not registered in another account realm.
     *
     * @param  string  $attribute  Validated attribute name
     * @param  mixed  $value  Submitted email value
     * @param  Closure  $fail  Validation failure callback
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }
        $email = mb_strtolower(trim($value));
        foreach (['users', 'supplier_users', 'customers'] as $table) {
            if ($table === $this->ownTable) {
                continue;
            }
            $query = DB::table($table)->whereRaw('LOWER(email) = ?', [$email]);
            if ($table === 'customers') {
                // Unverified retail claims must not block staff/supplier creation (CRITICAL-01);
                // only verified or commercial-tier customer emails reserve cross-realm ownership.
                $query->whereNull('deleted_at')
                    ->where(fn ($q) => $q->whereNotNull('email_verified_at')
                        ->orWhereIn('type', CustomerType::commercialValues()));
            }
            if ($query->exists()) {
                $fail($this->conceal
                    ? 'تعذر استخدام بيانات التسجيل المقدمة.'
                    : 'هذا البريد مستخدم بحساب آخر في النظام.');

                return;
            }
        }
    }
}
