<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Models\Quotation;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Shared\CostAccess;
use App\Services\Wholesale\QuotationService;
use App\Services\Wholesale\QuoteAdvisor;

final class SuggestQuotePricingTool implements AgentTool
{
    public function __construct(
        private QuoteAdvisor $advisor,
        private QuotationService $quotations,
    ) {}

    public function name(): string
    {
        return 'suggest_quote_pricing';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['quotations.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'يقترح سعر كل بند في عرض السعر: القائمة، المطلوب، والمقترح مع السبب. لا يحفظ الأسعار.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) ['quotation_id' => ['type' => 'INTEGER']],
                'required' => ['quotation_id'],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $quotation = Quotation::query()->with(['items.variant', 'customer'])->find((int) ($args['quotation_id'] ?? 0));
        if ($quotation === null) {
            return ToolOutcome::denied('عرض السعر مش موجود.', auditable: false);
        }
        $suggestion = $this->advisor->suggest($quotation, $actor);
        $warnings = $this->quotations->acceptanceWarnings($quotation);
        if (! CostAccess::canView($actor)) {
            $suggestion['lines'] = CostAccess::strip($suggestion['lines']);
        }

        return ToolOutcome::present([
            'quotation_id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'lines' => $suggestion['lines'],
            'warnings' => $warnings,
        ], [
            'type' => 'price_diff',
            'columns' => ['list_price', 'requested_price', 'suggested_price'],
            'lines' => $suggestion['lines'],
        ], 'اقتراح تسعير '.$quotation->quotation_number);
    }
}
