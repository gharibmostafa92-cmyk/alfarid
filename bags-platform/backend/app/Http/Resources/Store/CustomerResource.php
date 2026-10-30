<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** تمثيل العميل لقنوات العميل نفسه (متجر/جملة) — بلا أي بيانات داخلية. */
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
            'email_verified_at' => $this->email_verified_at,
            'phone' => $this->phone,
            'phone_verified_at' => $this->phone_verified_at,
            'type' => $this->type,
            'status' => $this->status,
            'company_name' => $this->company_name,
            'tax_number' => $this->tax_number,
            'commercial_register' => $this->commercial_register,
            'customer_group_id' => $this->customer_group_id,
            'credit_limit' => $this->credit_limit,
            'wallet_balance' => $this->wallet_balance,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at,
        ];
    }
}
