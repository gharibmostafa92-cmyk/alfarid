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
use App\Services\QuotationService;
use App\Services\Wholesale\QuotationVersion;

final class ExtendQuotationValidityTool implements WriteTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private QuotationService $quotations,
    ) {}

    public function name(): string
    {
        return 'extend_quotation_validity';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['quotations.price'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'يمد صلاحية عرض سعر مسعّر لتاريخ جديد.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'quotation_id' => ['type' => 'INTEGER'],
                    'valid_until' => ['type' => 'STRING'],
                ],
                'required' => ['quotation_id', 'valid_until'],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $quotation = Quotation::query()->find((int) ($args['quotation_id'] ?? 0));
        $until = trim((string) ($args['valid_until'] ?? ''));
        if ($quotation === null || $until === '') {
            return ToolOutcome::denied('العرض وتاريخ الصلاحية مطلوبين.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['quotation_id' => $quotation->id, 'valid_until' => $until],
            title: 'تمديد '.$quotation->quotation_number,
            before: ['valid_until' => $quotation->valid_until?->toDateString()],
            after: ['valid_until' => $until],
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
            $updated = $this->quotations->extendValidity($quotation, (string) $action->args['valid_until']);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }

        return ToolOutcome::result(true, 'اتمد الصلاحية لـ '.$updated->valid_until?->toDateString());
    }
}
