<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/** يمنع تسجيل بريد مستخدم أصلًا في نوع حساب آخر (users / supplier_users / customers). */
final class EmailNotUsedByOtherAccountType implements ValidationRule
{
    public function __construct(private readonly string $ownTable) {}

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
                $query->whereNull('deleted_at');
                // M13: إيميل عميل غير موثّق لا يحجز الإيميل — أي شخص يسجل به
                // مستقبلًا يمنع إنشاء حساب الموظف/المورد، والعميل نفسه لا
                // يستطيع الدخول بهذا الإيميل أصلًا.
                $query->whereNotNull('email_verified_at');
            }
            if ($query->exists()) {
                $fail('هذا البريد مستخدم بحساب آخر في النظام.');

                return;
            }
        }
    }
}
