<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\AiPendingAction;
use App\Models\ShippingCompany;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Shipping\LocksShippingRows;
use App\Services\Ai\Shipping\ShippingArgs;
use App\Services\Ai\Shipping\ShippingPresenter;
use App\Services\Ai\Shipping\ShippingVersions;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Auth\StaffAdministration;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpsertShippingCompanyTool implements LocksTargets, WriteTool
{
    use LocksShippingRows;

    public function __construct(private ProposalBuilder $proposals) {}

    public function name(): string
    {
        return 'upsert_shipping_company';
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
            'description' => 'إنشاء أو تعديل اسم شركة شحن وكودها وقالب التتبع. مفاتيح التكامل ممنوعة. تغيير النشاط بأداة set_shipping_company_status. يحتاج تأكيد.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'company_id' => ['type' => 'INTEGER'],
                    'name' => ['type' => 'STRING'],
                    'code' => ['type' => 'STRING'],
                    'tracking_url_template' => ['type' => 'STRING'],
                    'is_active' => ['type' => 'BOOLEAN', 'description' => 'حالة الشركة عند الإنشاء فقط'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        ShippingArgs::rejectSecrets($args);
        $id = isset($args['company_id']) ? (int) $args['company_id'] : null;
        if ($id && ! $actor->can('shipping.update')) {
            return ToolOutcome::denied('تعديل شركة الشحن محتاج shipping.update.');
        }
        if (! $id && ! $actor->can('shipping.create')) {
            return ToolOutcome::denied('إنشاء شركة الشحن محتاج shipping.create.');
        }
        $company = $id ? ShippingCompany::query()->find($id) : null;
        if ($id && ! $company) {
            return ToolOutcome::denied('شركة الشحن غير موجودة.', auditable: false);
        }
        if ($company && array_key_exists('is_active', $args) && (bool) $args['is_active'] !== $company->is_active) {
            return ToolOutcome::denied('تغيير حالة شركة موجودة يتم بأداة set_shipping_company_status.', auditable: false);
        }
        $data = [
            'name' => trim((string) ($args['name'] ?? $company?->name ?? '')),
            'code' => strtolower(trim((string) ($args['code'] ?? $company?->code ?? ''))),
            'tracking_url_template' => array_key_exists('tracking_url_template', $args)
                ? ($args['tracking_url_template'] === '' ? null : $args['tracking_url_template'])
                : $company?->tracking_url_template,
        ];
        if (! $company) {
            $data['is_active'] = $args['is_active'] ?? true;
        }
        Validator::make($data, [
            'is_active' => ['sometimes', 'boolean'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'regex:/^[a-z0-9_-]{1,40}$/', Rule::unique('shipping_companies', 'code')->ignore($company?->id)],
            'tracking_url_template' => ['nullable', 'string', 'max:500', 'starts_with:https://', 'regex:/\{awb\}/'],
        ])->validate();
        if ($company && $data['name'] === $company->name && $data['code'] === $company->code && $data['tracking_url_template'] === $company->tracking_url_template) {
            return ToolOutcome::denied('مفيش تغيير.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data + ['company_id' => $company?->id],
            risk: $this->risk(),
            title: $company ? 'تعديل شركة الشحن: '.$company->name : 'إنشاء شركة شحن: '.$data['name'],
            before: $company ? ShippingPresenter::company($company) : null,
            after: $data,
            expectedVersions: $company ? ['company:'.$company->id => ShippingVersions::of($company)] : [],
            inverse: $company ? [
                'name' => $company->name,
                'code' => $company->code,
                'tracking_url_template' => $company->tracking_url_template,
            ] : null,
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        abort_unless($actor->can(empty($action->args['company_id']) ? 'shipping.create' : 'shipping.update'), 403);

        $args = $action->args;
        unset($args['api_credentials']);
        if (! empty($args['company_id'])) {
            $company = ShippingCompany::query()->lockForUpdate()->findOrFail((int) $args['company_id']);
            ShippingVersions::assertSame($company, (string) ($action->expected_versions['company:'.$company->id] ?? ''));
            $company->update([
                'name' => $args['name'],
                'code' => $args['code'],
                'tracking_url_template' => $args['tracking_url_template'],
            ]);
        } else {
            $company = ShippingCompany::query()->create([
                'name' => $args['name'],
                'code' => $args['code'],
                'tracking_url_template' => $args['tracking_url_template'],
                'is_active' => (bool) ($args['is_active'] ?? true),
            ]);
        }
        StaffAdministration::audit($actor, 'shipping.company_saved', 'shipping_company', $company->id, ['name', 'code'], [], ['name' => $company->name, 'code' => $company->code], ['channel' => 'ai', 'tool' => $this->name(), 'action_id' => $action->id]);

        return ToolOutcome::result(true, 'تم حفظ شركة الشحن.', ['company' => ShippingPresenter::company($company->fresh()->loadCount('rates'))]);
    }
}
