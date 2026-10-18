<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Return;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** تقديم طلب إرجاع من حساب العميل — مع صور عيوب اختيارية لكل بند. */
class StoreReturnRequest extends FormRequest
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
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reason' => ['required', 'string', 'max:2000'],
            'refund_method' => ['nullable', Rule::in(array_column(RefundMethod::cases(), 'value'))],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.requested_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.item_reason' => ['nullable', 'string', 'max:255'],
            'items.*.attachments' => ['nullable', 'array', 'max:5'],
            'items.*.attachments.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * الحمولة الجاهزة للخدمة: البيانات المتحقق منها + ملفات كل بند.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        foreach (array_keys($data['items'] ?? []) as $i) {
            $files = $this->file("items.{$i}.attachments");
            $data['items'][$i]['attachments'] = is_array($files) ? $files : ($files !== null ? [$files] : []);
        }

        return $data;
    }
}
