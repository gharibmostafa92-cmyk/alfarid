<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Wholesale\QuotationService;
use App\Services\Wholesale\QuotationVersion;

final class ExpireQuotationTool implements WriteTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private QuotationService $quotations,
    ) {}

    public function name(): string
    {
        return 'expire_quotation';
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
        return ['quotations.price'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'ينهي صلاحية عرض سعر مسعّر انتهت مدته.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) ['quotation_id' => ['type' => 'INTEGER']],
                'required' => ['quotation_id'],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $quotation = Quotation::query()->find((int) ($args['quotation_id'] ?? 0));
        if ($quotation === null) {
            return ToolOutcome::denied('عرض السعر مش موجود.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['quotation_id' => $quotation->id],
            title: 'إنهاء '.$quotation->quotation_number,
            before: ['status' => $quotation->status->value],
            after: ['status' => 'expired'],
            expectedVersions: ['q:'.$quotation->id => QuotationVersion::hash($quotation)],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $quotation = Quotation::query()->findOrFail((int) $action->args['quotation_id']);
        if (($action->expected_versions['q:'.$quotation->id] ?? null) !== QuotationVersion::hash($quotation)) {
            return ToolOutcome::denied('عرض السعر اتغير بعد الاقتراح.', auditable: false);
        }
        try {
            $this->quotations->expire($quotation);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }

        return ToolOutcome::result(true, 'انتهت صلاحية العرض.');
    }
}
