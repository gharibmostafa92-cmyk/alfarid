<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\AiPendingAction;
use App\Models\ShippingRate;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Shipping\LocksShippingRows;
use App\Services\Ai\Shipping\ShippingVersions;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\StaffAdministration;

final class DeleteShippingRateTool implements LocksTargets, UndoableTool, WriteTool
{
    use LocksShippingRows;

    public function __construct(private ProposalBuilder $proposals) {}

    public function name(): string
    {
        return 'delete_shipping_rate';
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
        return ['shipping.delete'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'حذف تعريفة شحن. لو هي آخر مسار لمدن منطقة نشطة لازم تأكيد مكتوب باسم المنطقة. يحتاج تأكيد.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['rate_id'],
                'properties' => (object) ['rate_id' => ['type' => 'INTEGER']],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $rate = ShippingRate::query()->with(['zone.cities', 'company'])->find((int) ($args['rate_id'] ?? 0));
        if (! $rate) {
            return ToolOutcome::denied('التعريفة غير موجودة.', auditable: false);
        }
        $lastPath = $this->isLastPath($rate);
        $snapshot = $rate->only(['shipping_company_id', 'shipping_zone_id', 'base_cost', 'free_shipping_threshold', 'cod_fee', 'estimated_days_min', 'estimated_days_max']);

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['rate_id' => $rate->id],
            risk: $this->risk(),
            title: 'حذف تعريفة شحن',
            before: $snapshot,
            after: null,
            warnings: $lastPath ? ['دي آخر تعريفة نشطة لمدن المنطقة. بعد الحذف العرض هيرجع لتكلفة المدينة.'] : [],
            expectedVersions: ['rate:'.$rate->id => ShippingVersions::of($rate)],
            inverse: $snapshot,
            typedConfirmation: $lastPath ? $rate->zone->name : null,
            typedConfirmationHint: $lastPath ? 'اسم المنطقة' : null,
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $rate = ShippingRate::query()->lockForUpdate()->findOrFail((int) $action->args['rate_id']);
        ShippingVersions::assertSame($rate, (string) ($action->expected_versions['rate:'.$rate->id] ?? ''));
        $rate->setRelation('zone', $rate->zone()->lockForUpdate()->first());
        if ($this->isLastPath($rate) && ($action->preview['typed_confirmation'] ?? null) !== $rate->zone->name) {
            return ToolOutcome::result(false, 'دي دلوقتي آخر تعريفة للمنطقة. اطلب اقتراح جديد وأكّد باسم المنطقة.');
        }
        $id = $rate->id;
        $rate->delete();
        StaffAdministration::audit($actor, 'shipping.rate_deleted', 'shipping_rate', $id, [], [], [], ['channel' => 'ai', 'tool' => $this->name(), 'action_id' => $action->id]);

        return ToolOutcome::result(true, 'تم حذف تعريفة الشحن.', ['rate_id' => $id]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && isset($action->inverse['shipping_company_id']);
    }

    private function isLastPath(ShippingRate $rate): bool
    {
        return $rate->zone?->is_active
            && $rate->zone->cities->isNotEmpty()
            && ! ShippingRate::query()
                ->where('shipping_zone_id', $rate->shipping_zone_id)
                ->where('id', '!=', $rate->id)
                ->whereHas('company', fn ($q) => $q->where('is_active', true))
                ->exists();
    }

    public function targetState(AiPendingAction $action): string
    {
        return ShippingRate::query()->whereKey((int) $action->args['rate_id'])->exists() ? 'present' : 'missing';
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        if (ShippingRate::query()->whereKey((int) $action->args['rate_id'])->exists()) {
            return ToolOutcome::result(false, 'التعريفة لسه موجودة.');
        }
        $rate = ShippingRate::query()->create($action->inverse);

        return ToolOutcome::result(true, 'تم استرجاع تعريفة الشحن.', ['rate_id' => $rate->id]);
    }
}
