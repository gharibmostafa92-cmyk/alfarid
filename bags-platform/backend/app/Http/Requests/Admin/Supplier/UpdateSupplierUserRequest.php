<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تعديل حساب موظف مورد (بيانات/تفعيل/كلمة مرور اختيارية). */
class UpdateSupplierUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('user')?->id ?? $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('supplier_users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
