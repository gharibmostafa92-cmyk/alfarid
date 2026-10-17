<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** تتبع عام للطلب: الحالة والسجل فقط (بلا بيانات مالية أو شخصية زائدة). */
class TrackOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'status' => $this->status,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'product_name' => $item->product_name_snapshot,
                'qty' => $item->qty,
            ])->all()),
            // بلا notes: ملاحظات السجل داخلية (أدمن/نظام) ولا تُعرض للعامة (M5)
            'status_history' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories
                ->sortBy('id')
                ->map(fn ($history): array => [
                    'status' => $history->to_status,
                    'at' => $history->created_at,
                ])->values()->all()),
            'created_at' => $this->created_at,
        ];
    }
}
