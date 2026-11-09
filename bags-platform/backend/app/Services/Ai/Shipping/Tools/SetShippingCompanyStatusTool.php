<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\AiPendingAction;
use App\Models\ShippingCompany;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Shipping\LocksShippingRows;
use App\Services\Ai\Shipping\ShippingVersions;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Auth\StaffAdministration;

final class SetShippingCompanyStatusTool implements LocksTargets, UndoableTool, WriteTool
{
    use LocksShippingRows;

    public function __construct(private ProposalBuilder $proposals) {}

    public function name(): string
    {
        return 'set_shipping_company_status';
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
            'description' => 'تفعيل أو إيقاف شركة شحن. الإيقاف يطلّع مدنها على تكلفة المدينة الاحتياطية. يحتاج تأكيدًا بكود الشركة.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['company_id', 'is_active'],
                'properties' => (object) [
                    'company_id' => ['type' => 'INTEGER'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $company = ShippingCompany::query()->find((int) ($args['company_id'] ?? 0));
        if (! $company) {
            return ToolOutcome::denied('شركة الشحن غير موجودة.', auditable: false);
        }
        if (! array_key_exists('is_active', $args)) {
            return ToolOutcome::denied('حدد is_active.', auditable: false);
        }
        $wanted = (bool) $args['is_active'];
        if ((bool) $company->is_active === $wanted) {
            return ToolOutcome::denied('الشركة بالفعل '.($wanted ? 'نشطة.' : 'موقوفة.'), auditable: false);
        }
        $warnings = $wanted ? [] : ['الإيقاف يطلّع مدن الشركة على تكلفة المدينة الاحتياطية ورسوم تحصيل صفر طالما مفيش شركة تانية فائزة.'];

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['company_id' => $company->id, 'is_active' => $wanted],
            risk: $this->risk(),
            title: ($wanted ? 'تفعيل' : 'إيقاف').' شركة الشحن: '.$company->name,
            before: ['is_active' => (bool) $company->is_active],
            after: ['is_active' => $wanted],
            warnings: $warnings,
            expectedVersions: ['company:'.$company->id => ShippingVersions::of($company)],
            inverse: ['is_active' => (bool) $company->is_active],
            typedConfirmation: $company->code,
            typedConfirmationHint: 'كود الشركة',
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $company = ShippingCompany::query()->lockForUpdate()->findOrFail((int) $action->args['company_id']);
        ShippingVersions::assertSame($company, (string) ($action->expected_versions['company:'.$company->id] ?? ''));
        $company->update(['is_active' => (bool) $action->args['is_active']]);
        StaffAdministration::audit($actor, 'shipping.company_status', 'shipping_company', $company->id, ['is_active'], [], ['is_active' => $company->is_active], ['channel' => 'ai', 'tool' => $this->name(), 'action_id' => $action->id]);

        return ToolOutcome::result(true, 'تم تحديث حالة شركة الشحن.', ['company_id' => $company->id, 'is_active' => (bool) $company->is_active]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && array_key_exists('is_active', $action->inverse);
    }

    public function targetState(AiPendingAction $action): string
    {
        $company = ShippingCompany::query()->find((int) $action->args['company_id']);

        return $company ? ShippingVersions::of($company) : 'missing';
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $company = ShippingCompany::query()->lockForUpdate()->findOrFail((int) $action->args['company_id']);
        $company->update(['is_active' => (bool) $action->inverse['is_active']]);

        return ToolOutcome::result(true, 'تم التراجع عن حالة شركة الشحن.', ['company_id' => $company->id]);
    }
}
