<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory;

use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TransferTimeline
{
    public static function build(StockTransfer $transfer): array
    {
        $events = DB::table('stock_movements')->where('reference_id', $transfer->id)
            ->whereIn('reference_type', [$transfer->getMorphClass(), StockTransfer::class])
            ->selectRaw('type, MIN(created_at) AS happened_at, MIN(created_by) AS actor_id')->groupBy('type')->get()->keyBy('type');
        $rank = ['draft' => 0, 'approved' => 1, 'shipped' => 2, 'received' => 3, 'cancelled' => -1];
        $stage = $rank[$transfer->status->value] ?? 0;
        $names = User::whereIn('id', array_filter([$transfer->created_by, $transfer->approved_by, ...$events->pluck('actor_id')->all()]))->pluck('name', 'id');
        $rows = [];
        foreach (['draft', 'approved', 'shipped', 'received'] as $index => $name) {
            $event = match ($name) {
                'approved' => $events->get('reserve'),
                'shipped' => $events->get('transfer_out') ?? $events->get('transfer_in_transit') ?? ($stage >= 2 ? $events->get('release_reserve') : null),
                'received' => $events->get('transfer_transit_release') ?? $events->get('transfer_in') ?? $events->get('transfer_shortage'),
                default => null,
            };
            $at = match ($name) {
                'draft' => $transfer->created_at,
                'shipped' => $transfer->shipped_at,
                'received' => $transfer->received_at,
                default => null,
            };
            $actorId = $event?->actor_id ?? ($name === 'draft' ? $transfer->created_by : ($name === 'approved' ? $transfer->approved_by : null));
            $rows[] = ['stage' => $name, 'done' => $name === 'draft' || $stage >= $index || $event !== null,
                'at' => $at?->toIso8601String() ?? ($event ? Carbon::parse($event->happened_at)->toIso8601String() : null),
                'actor' => $actorId !== null ? $names->get($actorId) : null];
        }

        return $rows;
    }
}
