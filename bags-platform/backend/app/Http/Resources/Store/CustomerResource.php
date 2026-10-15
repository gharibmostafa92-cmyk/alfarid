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
            'phone' => $this->phone,
            'type' => $this->type,
            'status' => $this->status,
            'company_name' => $this->company_name,
            'customer_group_id' => $this->customer_group_id,
            'credit_limit' => $this->credit_limit,
            'created_at' => $this->created_at,
        ];
    }
}
