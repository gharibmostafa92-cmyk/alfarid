<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Supplier;

use App\Models\SupplierUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Supplier portal user resource representation.
 *
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
            // Handle null supplier safely in case the parent entity was removed.
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),
        ];
    }
}
