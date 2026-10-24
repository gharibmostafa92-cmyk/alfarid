<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Enums\StocktakeStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StocktakeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stocktake_number' => $this->stocktake_number,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null),
            'status' => $this->status,
            'items_count' => $this->whenCounted('items'),
            'created_by' => $this->created_by,
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'notes' => $this->notes,
            // M6: الجرد "الأعمى" — الرصيد الدفتري والفرق للمعتمِد فقط (أو بعد
            // الاعتماد)، فمن يعدّ لا يرى ما يُخفي به العجز.
            'items' => $this->whenLoaded('items', function () use ($request) {
                $reveal = $this->status === StocktakeStatus::Approved
                    || $request->user()?->can('stocktakes.approve');

                return $this->items->map(function ($item) use ($reveal) {
                    $row = [
                        'id' => $item->id,
                        'product_variant_id' => $item->product_variant_id,
                        'sku' => $item->relationLoaded('variant') ? $item->variant?->sku : null,
                    ];
                    if ($reveal) {
                        $row['system_qty'] = $item->system_qty;
                    }
                    $row['counted_qty'] = $item->counted_qty;
                    if ($reveal) {
                        $row['difference'] = $item->difference;
                    }

                    return $row;
                })->all();
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
