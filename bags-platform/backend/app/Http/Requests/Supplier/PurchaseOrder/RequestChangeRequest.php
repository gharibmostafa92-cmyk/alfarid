<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/** طلب المورد تعديل أمر شراء: ملاحظات التعديل المقترح إلزامية ليعالجها مدير المشتريات. */
class RequestChangeRequest extends FormRequest
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
            'notes' => ['required', 'string', 'max:2000'],
        ];
    }
}
