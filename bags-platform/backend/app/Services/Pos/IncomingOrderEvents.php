<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class IncomingOrderEvents
{
    public const OPEN_STATUSES = ['pending', 'confirmed', 'processing', 'shipped'];

    public function publish(Order $order, string $kind): void
    {
        if (! in_array($order->channel, ['store', 'wholesale'], true)) {
            return;
        }

        $branches = $order->loadMissing('items')->items->pluck('allocated_branch_id')
            ->push($order->allocated_branch_id)->filter()->unique()->sort();

        if ($branches->isNotEmpty()) {
            $now = now();
            DB::table('pos_order_events')->insert($branches->map(fn ($branch): array => [
                'branch_id' => $branch,
                'order_id' => $order->id,
                'kind' => $kind,
                'created_at' => $now,
            ])->values()->all());
        }
    }

    public function position(int $branch): int
    {
        $stream = DB::table('pos_order_event_streams')->where('branch_id', $branch);
        $pending = $this->pending($branch);
        if (! (clone $pending)->exists()) {
            return (int) $stream->value('position');
        }

        // Only committed outbox rows get positions. Sequence allocation is
        // isolated from inventory and order locks, and can be retried on read.
        return DB::transaction(function () use ($stream, $pending, $branch): int {
            DB::table('pos_order_event_streams')->insertOrIgnore(['branch_id' => $branch]);
            $position = (int) (clone $stream)->lockForUpdate()->value('position');
            $ids = $pending->orderBy('id')->limit(200)->lockForUpdate()->pluck('id');
            if ($ids->isEmpty()) {
                return $position;
            }

            $bindings = [];
            foreach ($ids as $id) {
                $bindings[] = (int) $id;
                $bindings[] = ++$position;
            }
            $cases = str_repeat(' WHEN ? THEN CAST(? AS BIGINT)', $ids->count());
            $placeholders = implode(', ', array_fill(0, $ids->count(), '?'));
            DB::update(
                "UPDATE pos_order_events SET position = CASE id{$cases} END WHERE id IN ({$placeholders})",
                [...$bindings, ...$ids->all()],
            );
            $stream->update(['position' => $position]);

            return $position;
        });
    }

    public function hasPending(int $branch): bool
    {
        return $this->pending($branch)->exists();
    }

    private function pending(int $branch): Builder
    {
        return DB::table('pos_order_events')->where('branch_id', $branch)->whereNull('position');
    }
}
