<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Supplier;

use Illuminate\Foundation\Http\FormRequest;

/** إنشاء حساب موظف مورد (بوابة المورد) من إدارة المورد. */
class StoreSupplierUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:supplier_users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
