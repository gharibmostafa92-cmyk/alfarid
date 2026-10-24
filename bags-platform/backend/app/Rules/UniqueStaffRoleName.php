<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\StaffRole;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\Models\Role;

final class UniqueStaffRoleName implements ValidationRule
{
    public function __construct(private readonly ?int $except = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && StaffRole::isReserved($value)) {
            return; // The controller consistently rejects reserved names with 403.
        }
        if (is_string($value) && Role::query()->where('guard_name', 'staff')->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
            ->when($this->except !== null, fn ($query) => $query->whereKeyNot($this->except))->exists()) {
            $fail('اسم الدور مستخدم بالفعل.');
        }
    }
}
