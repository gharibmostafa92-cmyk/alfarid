<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier\Account;

use Illuminate\Foundation\Http\FormRequest;

/** كشف حساب المورد: فترة اختيارية (افتراضيًا من إنشاء المورد حتى اليوم). */
class StatementRequest extends FormRequest
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
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ];
    }
}
