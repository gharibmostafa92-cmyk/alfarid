<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\AiPendingAction;
use App\Models\ShippingZone;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Shipping\LocksShippingRows;
use App\Services\Ai\Shipping\ShippingArgs;
use App\Services\Ai\Shipping\ShippingVersions;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Auth\StaffAdministration;
use Illuminate\Support\Facades\Validator;

final class UpsertShippingZoneTool implements LocksTargets, WriteTool
{
    use LocksShippingRows;

    public function __construct(private ProposalBuilder $proposals) {}

    public function name(): string
    {
        return 'upsert_shipping_zone';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'medium';
    }

    public function permissions(): array
    {
        return ['shipping.create', 'shipping.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء أو تعديل اسم منطقة الشحن ونشاطها. ربط المدن بأداة sync_zone_cities. يحتاج تأكيد.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'zone_id' => ['type' => 'INTEGER'],
                    'name' => ['type' => 'STRING'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        ShippingArgs::rejectSecrets($args);
        if (array_key_exists('city_ids', $args)) {
            return ToolOutcome::denied('ربط المدن يتم بأداة sync_zone_cities بعد حفظ المنطقة.', auditable: false);
        }
        $id = isset($args['zone_id']) ? (int) $args['zone_id'] : null;
        if ($id && ! $actor->can('shipping.update')) {
            return ToolOutcome::denied('تعديل المنطقة محتاج shipping.update.');
        }
        if (! $id && ! $actor->can('shipping.create')) {
            return ToolOutcome::denied('إنشاء المنطقة محتاج shipping.create.');
        }
        $zone = $id ? ShippingZone::query()->find($id) : null;
        if ($id && ! $zone) {
            return ToolOutcome::denied('منطقة الشحن غير موجودة.', auditable: false);
        }
        $data = [
            'name' => trim((string) ($args['name'] ?? $zone?->name ?? '')),
            'is_active' => array_key_exists('is_active', $args) ? (bool) $args['is_active'] : (bool) ($zone?->is_active ?? true),
        ];
        Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ])->validate();

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data + ['zone_id' => $zone?->id],
            risk: $this->risk(),
            title: $zone ? 'تعديل منطقة الشحن: '.$zone->name : 'إنشاء منطقة شحن: '.$data['name'],
            before: $zone ? ['name' => $zone->name, 'is_active' => (bool) $zone->is_active] : null,
            after: $data,
            expectedVersions: $zone ? ['zone:'.$zone->id => ShippingVersions::of($zone)] : [],
            inverse: $zone ? ['name' => $zone->name, 'is_active' => (bool) $zone->is_active] : null,
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        abort_unless($actor->can(empty($action->args['zone_id']) ? 'shipping.create' : 'shipping.update'), 403);

        $args = $action->args;
        if (! empty($args['city_ids'])) {
            return ToolOutcome::result(false, 'اطلب اقتراح حفظ المنطقة الأول، وبعدها اربط المدن في إجراء منفصل.');
        }
        $data = ['name' => $args['name'], 'is_active' => (bool) $args['is_active']];
        if (! empty($args['zone_id'])) {
            $zone = ShippingZone::query()->lockForUpdate()->findOrFail((int) $args['zone_id']);
            ShippingVersions::assertSame($zone, (string) ($action->expected_versions['zone:'.$zone->id] ?? ''));
            $zone->update($data);
        } else {
            $zone = ShippingZone::query()->create($data);
        }
        StaffAdministration::audit($actor, 'shipping.zone_saved', 'shipping_zone', $zone->id, ['name'], [], ['name' => $zone->name], ['channel' => 'ai', 'tool' => $this->name(), 'action_id' => $action->id]);

        return ToolOutcome::result(true, 'تم حفظ منطقة الشحن.', ['zone_id' => $zone->id, 'name' => $zone->name]);
    }
}
