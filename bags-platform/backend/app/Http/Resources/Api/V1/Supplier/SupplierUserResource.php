<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Supplier;

use App\Models\SupplierUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierUser
 */
class SupplierUserResource extends JsonResource
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
            'supplier_id' => $this->supplier_id,
            // M4: المورد قد يكون محذوفًا (supplier_id بلا FK قابل للتنفيذ على
            // بيانات قديمة) — لا تفترض وجوده.
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),
        ];
    }
}
