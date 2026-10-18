<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_number' => $this->tax_number,
            'commercial_register' => $this->commercial_register,
            'address' => $this->address,
            'payment_terms_days' => $this->payment_terms_days,
            'opening_balance' => $this->opening_balance,
            'current_balance' => $this->current_balance,
            'is_active' => $this->is_active,
            'users' => SupplierUserResource::collection($this->whenLoaded('users')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
