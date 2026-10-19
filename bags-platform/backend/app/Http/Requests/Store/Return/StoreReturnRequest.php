<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Return;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'order_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
            'refund_method' => ['nullable', Rule::in(array_column(RefundMethod::cases(), 'value'))],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.requested_qty' => ['required', 'integer', 'gt:0', 'max:99999999'],
            'items.*.item_reason' => ['nullable', 'string', 'max:255'],
            'items.*.attachments' => ['nullable', 'array', 'max:5'],
            'items.*.attachments.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $files = 0;
            $kilobytes = 0;
            foreach (array_keys($this->input('items', []) ?? []) as $index) {
                $itemFiles = $this->file("items.{$index}.attachments");
                $itemFiles = is_array($itemFiles) ? $itemFiles : ($itemFiles !== null ? [$itemFiles] : []);
                foreach ($itemFiles as $file) {
                    if (! is_object($file) || ! method_exists($file, 'getSize')) {
                        continue;
                    }
                    $files++;
                    $kilobytes += (int) ceil($file->getSize() / 1024);
                }
            }
            if ($files > 10) {
                $validator->errors()->add('items', 'الحد الأقصى لمرفقات طلب الإرجاع 10 ملفات.');
            }
            if ($kilobytes > 25600) {
                $validator->errors()->add('items', 'الحجم الإجمالي لمرفقات طلب الإرجاع يجب ألا يتجاوز 25 ميغابايت.');
            }
        });
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
