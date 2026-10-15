<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** تمثيل العميل في لوحة الإدارة — كامل البيانات التشغيلية بما فيها الاعتماد والمستندات. */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'type' => $this->type,
            'status' => $this->status,
            'company_name' => $this->company_name,
            'tax_number' => $this->tax_number,
            'commercial_register' => $this->commercial_register,
            'credit_limit' => $this->credit_limit,
            'customer_group_id' => $this->customer_group_id,
            'customer_group' => $this->whenLoaded('customerGroup', fn () => $this->customerGroup ? [
                'id' => $this->customerGroup->id,
                'name' => $this->customerGroup->name,
                'discount_percentage' => $this->customerGroup->discount_percentage,
            ] : null),
            'rejection_reason' => $this->rejection_reason,
            'suspension_reason' => $this->suspension_reason,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document) => [
                'id' => $document->id,
                'document_type' => $document->document_type,
                'status' => $document->status,
                'original_name' => $document->original_name,
                'mime_type' => $document->mime_type,
                // تنزيل محمي بصلاحيات الأدمن من التخزين الخاص — لا رابط عام
                'download_url' => route('admin.customers.documents.download', ['customer' => $this->id, 'document' => $document->id]),
                'created_at' => $document->created_at,
            ])->all()),
            'addresses' => $this->whenLoaded('addresses', fn () => $this->addresses->map(fn ($address) => [
                'id' => $address->id,
                'city' => $address->relationLoaded('city') && $address->city ? [
                    'id' => $address->city->id, 'name_ar' => $address->city->name_ar, 'name_en' => $address->city->name_en,
                ] : null,
                'address_line' => $address->address_line,
                'building_no' => $address->building_no,
                'floor_no' => $address->floor_no,
                'apartment_no' => $address->apartment_no,
                'is_default' => $address->is_default,
            ])->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
