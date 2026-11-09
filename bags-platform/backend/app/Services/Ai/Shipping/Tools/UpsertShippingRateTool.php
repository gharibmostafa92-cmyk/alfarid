<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\AiPendingAction;
use App\Models\ShippingCompany;
use App\Models\ShippingRate;
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
use App\Services\Auth\StaffAdministration;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpsertShippingRateTool implements LocksTargets, UndoableTool, WriteTool
{
    use LocksShippingRows;

    public function __construct(private ProposalBuilder $proposals) {}

    public function name(): string
    {
        return 'upsert_shipping_rate';
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
        return ['shipping.create', 'shipping.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء أو تعديل تعريفة شحن: التكلفة ورسوم التحصيل وعتبة الشحن المجاني والأيام. الشركة أو المنطقة الموقوفة تتنحفظ بس مش هتظهر في العرض. يحتاج تأكيدًا بكود الشركة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'rate_id' => ['type' => 'INTEGER'],
                    'shipping_company_id' => ['type' => 'INTEGER'],
                    'shipping_zone_id' => ['type' => 'INTEGER'],
                    'base_cost' => ['type' => 'NUMBER'],
                    'free_shipping_threshold' => ['type' => 'NUMBER'],
                    'cod_fee' => ['type' => 'NUMBER'],
                    'estimated_days_min' => ['type' => 'INTEGER'],
                    'estimated_days_max' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        ShippingArgs::rejectSecrets($args);
        $rate = ! empty($args['rate_id']) ? ShippingRate::query()->find((int) $args['rate_id']) : null;
        if (! empty($args['rate_id']) && ! $rate) {
            return ToolOutcome::denied('التعريفة غير موجودة.', auditable: false);
        }
        if ($rate && ! $actor->can('shipping.update')) {
            return ToolOutcome::denied('تعديل التعريفة محتاج shipping.update.');
        }
        if (! $rate && ! $actor->can('shipping.create')) {
            return ToolOutcome::denied('إنشاء التعريفة محتاج shipping.create.');
        }
        $data = [
            'shipping_company_id' => (int) ($args['shipping_company_id'] ?? $rate?->shipping_company_id),
            'shipping_zone_id' => (int) ($args['shipping_zone_id'] ?? $rate?->shipping_zone_id),
            'base_cost' => ShippingArgs::money($args['base_cost'] ?? $rate?->base_cost, 'base_cost'),
            'free_shipping_threshold' => array_key_exists('free_shipping_threshold', $args)
                ? ($args['free_shipping_threshold'] === null || $args['free_shipping_threshold'] === '' ? null : ShippingArgs::money($args['free_shipping_threshold'], 'free_shipping_threshold', true))
                : ($rate?->free_shipping_threshold !== null ? (float) $rate->free_shipping_threshold : null),
            'cod_fee' => ShippingArgs::money($args['cod_fee'] ?? $rate?->cod_fee ?? 0, 'cod_fee'),
            'estimated_days_min' => (int) ($args['estimated_days_min'] ?? $rate?->estimated_days_min),
            'estimated_days_max' => (int) ($args['estimated_days_max'] ?? $rate?->estimated_days_max),
        ];
        Validator::make($data, [
            'shipping_company_id' => ['required', 'integer', 'exists:shipping_companies,id'],
            'shipping_zone_id' => ['required', 'integer', 'exists:shipping_zones,id', Rule::unique('shipping_rates', 'shipping_zone_id')->where('shipping_company_id', $data['shipping_company_id'])->ignore($rate?->id)],
            'base_cost' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'free_shipping_threshold' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'cod_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'estimated_days_min' => ['required', 'integer', 'min:0', 'max:365'],
            'estimated_days_max' => ['required', 'integer', 'gte:estimated_days_min', 'max:365'],
        ])->validate();
        $company = ShippingCompany::query()->findOrFail($data['shipping_company_id']);
        $zone = ShippingZone::query()->findOrFail($data['shipping_zone_id']);
        $warnings = [];
        if (! $company->is_active || ! $zone->is_active) {
            $warnings[] = 'الشركة أو المنطقة موقوفة؛ التعريفة لن تُستخدم في العرض لحد ما يتفعل الاتنين.';
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data + ['rate_id' => $rate?->id],
            risk: $this->risk(),
            title: ($rate ? 'تعديل' : 'إنشاء').' تعريفة شحن',
            before: $rate ? $rate->only(['base_cost', 'cod_fee', 'free_shipping_threshold', 'estimated_days_min', 'estimated_days_max']) : null,
            after: $data,
            warnings: $warnings,
            expectedVersions: $rate ? ['rate:'.$rate->id => ShippingVersions::of($rate)] : [],
            inverse: $rate ? $rate->only(['shipping_company_id', 'shipping_zone_id', 'base_cost', 'free_shipping_threshold', 'cod_fee', 'estimated_days_min', 'estimated_days_max']) : null,
            typedConfirmation: $company->code,
            typedConfirmationHint: 'كود الشركة',
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        abort_unless($actor->can(empty($action->args['rate_id']) ? 'shipping.create' : 'shipping.update'), 403);

        $payload = collect($action->args)->only(['shipping_company_id', 'shipping_zone_id', 'base_cost', 'free_shipping_threshold', 'cod_fee', 'estimated_days_min', 'estimated_days_max'])->all();
        if (! empty($action->args['rate_id'])) {
            $rate = ShippingRate::query()->lockForUpdate()->findOrFail((int) $action->args['rate_id']);
            ShippingVersions::assertSame($rate, (string) ($action->expected_versions['rate:'.$rate->id] ?? ''));
            $rate->update($payload);
        } else {
            $rate = ShippingRate::query()->create($payload);
        }
        StaffAdministration::audit($actor, 'shipping.rate_saved', 'shipping_rate', $rate->id, ['base_cost'], [], ['base_cost' => $rate->base_cost], ['channel' => 'ai', 'tool' => $this->name(), 'action_id' => $action->id]);

        return ToolOutcome::result(true, 'تم حفظ تعريفة الشحن.', ['rate_id' => $rate->id, 'base_cost' => (float) $rate->base_cost]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && isset($action->args['rate_id']);
    }

    public function targetState(AiPendingAction $action): string
    {
        $rate = ShippingRate::query()->find((int) ($action->args['rate_id'] ?? 0));

        return $rate ? ShippingVersions::of($rate) : 'missing';
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        abort_unless($actor->can('shipping.update'), 403, 'التراجع عن تعديل التعريفة محتاج صلاحية تعديل الشحن.');

        $rate = ShippingRate::query()->lockForUpdate()->findOrFail((int) $action->args['rate_id']);
        $rate->update($action->inverse);

        return ToolOutcome::result(true, 'تم التراجع عن تعريفة الشحن.', ['rate_id' => $rate->id]);
    }
}
