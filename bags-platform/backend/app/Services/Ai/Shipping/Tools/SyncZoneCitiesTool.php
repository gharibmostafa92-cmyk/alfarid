<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\AiPendingAction;
use App\Models\City;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Shipping\LocksShippingRows;
use App\Services\Ai\Shipping\ShippingArgs;
use App\Services\Ai\Shipping\ShippingVersions;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\StaffAdministration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SyncZoneCitiesTool implements LocksTargets, UndoableTool, WriteTool
{
    use LocksShippingRows;

    public function __construct(private ProposalBuilder $proposals) {}

    public function name(): string
    {
        return 'sync_zone_cities';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'high';
    }

    public function permissions(): array
    {
        return ['shipping.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'استبدال مدن منطقة الشحن. مدينة في منطقة نشطة تانية تترفض إلا لو allow_overlap=true، وساعتها العرض يفضل لأول شركة حسب المعرّف. يحتاج تأكيد.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['zone_id', 'city_ids'],
                'properties' => (object) [
                    'zone_id' => ['type' => 'INTEGER'],
                    'city_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                    'allow_overlap' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        ShippingArgs::rejectSecrets($args);
        $zone = ShippingZone::query()->with('cities')->find((int) ($args['zone_id'] ?? 0));
        if (! $zone) {
            return ToolOutcome::denied('منطقة الشحن غير موجودة.', auditable: false);
        }
        $ids = array_values(array_unique(array_map(intval(...), (array) ($args['city_ids'] ?? []))));
        Validator::make(['city_ids' => $ids], [
            'city_ids' => ['array', 'max:500'],
            'city_ids.*' => ['integer', 'exists:cities,id'],
        ])->validate();
        $conflicts = $this->conflicts($zone, $ids);
        if ($conflicts->isNotEmpty() && empty($args['allow_overlap'])) {
            return ToolOutcome::denied('المدن دي في منطقة نشطة تانية: '.$conflicts->implode(', ').'. ابعت allow_overlap=true لو عارف إن الفائز هيبقى أول شركة حسب المعرّف.', auditable: false);
        }
        $previous = $zone->cities->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $warnings = $conflicts->isEmpty() ? [] : ['فيه تداخل. العرض الحالي ياخد أول شركة نشطة حسب المعرّف مش الأرخص.'];

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['zone_id' => $zone->id, 'city_ids' => $ids, 'accepted_overlaps' => $conflicts->all()],
            risk: $this->risk(),
            title: 'تحديث مدن منطقة: '.$zone->name,
            before: ['city_ids' => $previous],
            after: ['city_ids' => $ids],
            warnings: $warnings,
            expectedVersions: ['zone:'.$zone->id => ShippingVersions::of($zone)],
            inverse: ['city_ids' => $previous],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $zone = ShippingZone::query()->lockForUpdate()->findOrFail((int) $action->args['zone_id']);
        ShippingVersions::assertSame($zone, (string) ($action->expected_versions['zone:'.$zone->id] ?? ''));
        $ids = $action->args['city_ids'] ?? [];
        City::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get(['id']);
        if ($this->conflicts($zone, $ids)->diff($action->args['accepted_overlaps'] ?? [])->isNotEmpty()) {
            return ToolOutcome::result(false, 'تداخل المدن اتغيّر بعد المعاينة. اطلب اقتراح جديد وراجعه.');
        }
        $zone->cities()->sync($ids);
        $zone->touch();
        StaffAdministration::audit($actor, 'shipping.zone_cities', 'shipping_zone', $zone->id, ['count'], [], ['count' => count($action->args['city_ids'] ?? [])], ['channel' => 'ai', 'tool' => $this->name(), 'action_id' => $action->id]);

        return ToolOutcome::result(true, 'تم تحديث مدن المنطقة.', ['zone_id' => $zone->id]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && array_key_exists('city_ids', $action->inverse);
    }

    /** @param list<int> $ids */
    private function conflicts(ShippingZone $zone, array $ids): Collection
    {
        return DB::table('shipping_zone_cities')
            ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_zone_cities.shipping_zone_id')
            ->where('shipping_zones.is_active', true)
            ->where('shipping_zone_cities.shipping_zone_id', '!=', $zone->id)
            ->whereIn('shipping_zone_cities.city_id', $ids)
            ->distinct()
            ->pluck('shipping_zone_cities.city_id');
    }

    public function targetState(AiPendingAction $action): string
    {
        $zone = ShippingZone::query()->find((int) $action->args['zone_id']);

        return $zone ? ShippingVersions::of($zone) : 'missing';
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $zone = ShippingZone::query()->lockForUpdate()->findOrFail((int) $action->args['zone_id']);
        $zone->cities()->sync($action->inverse['city_ids'] ?? []);

        return ToolOutcome::result(true, 'تم التراجع عن مدن المنطقة.', ['zone_id' => $zone->id]);
    }
}
