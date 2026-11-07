<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Models\Quotation;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\CostAccess;
use App\Services\PricingService;
use App\Services\QuotationService;
use Throwable;

final class GetQuotationTool implements AgentTool
{
    public function __construct(
        private PricingService $pricing,
        private QuotationService $quotations,
    ) {}

    public function name(): string
    {
        return 'get_quotation';
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
            'description' => 'تفاصيل عرض سعر، مع سعر القائمة وطلب التاجر والسعر المعروض والهامش لكل بند.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) ['quotation_id' => ['type' => 'INTEGER']],
                'required' => ['quotation_id'],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $quotation = Quotation::query()->with(['customer', 'items.variant.product'])->find((int) ($args['quotation_id'] ?? 0));
        if ($quotation === null) {
            return ToolOutcome::denied('عرض السعر مش موجود.', auditable: false);
        }

        $showCost = CostAccess::canView($actor);
        $lines = [];
        foreach ($quotation->items as $item) {
            $variant = $item->variant;
            $list = null;
            $tiers = [];
            if ($variant !== null) {
                try {
                    $resolved = $this->pricing->resolve($variant, $quotation->customer, (float) $item->qty);
                    $list = $resolved['unit_price'];
                    $tiers = $this->pricing->tiersFor($variant, $quotation->customer);
                } catch (Throwable) {
                    $list = null;
                }
            }
            $offered = (float) $item->offered_unit_price;
            $cost = $showCost ? (float) ($variant?->cost_price ?? 0) : null;
            $margin = $showCost && $offered > 0 && $cost !== null ? round((($offered - $cost) / $offered) * 100, 2) : null;
            $lines[] = [
                'item_id' => $item->id,
                'sku' => $variant?->sku,
                'name' => $variant?->product?->name_ar,
                'qty' => (float) $item->qty,
                'list_price' => $list,
                'requested_target_price' => $item->requested_target_price !== null ? (float) $item->requested_target_price : null,
                'offered_unit_price' => $offered,
                'cost' => $cost,
                'margin_percent' => $margin,
                'tiers' => $tiers,
            ];
        }

        $data = [
            'id' => $quotation->id,
            'number' => $quotation->quotation_number,
            'status' => $quotation->status->value,
            'revision' => (int) $quotation->revision,
            'source' => $quotation->source?->value,
            'valid_until' => $quotation->valid_until?->toDateString(),
            'customer' => $quotation->customer?->company_name ?: $quotation->customer?->name,
            'grand_total' => (string) $quotation->grand_total,
            'lines' => $lines,
            'acceptance_warnings' => $this->quotations->acceptanceWarnings($quotation),
        ];

        $rows = array_map(fn (array $line) => [
            $line['sku'],
            (string) $line['qty'],
            (string) ($line['list_price'] ?? ''),
            (string) ($line['requested_target_price'] ?? ''),
            (string) $line['offered_unit_price'],
        ], $lines);

        return ToolOutcome::data($data, [
            'type' => 'quotation_card',
            'id' => $quotation->id,
            'number' => $quotation->quotation_number,
            'status' => $quotation->status->value,
            'columns' => ['الصنف', 'الكمية', 'سعر القائمة', 'طلب التاجر', 'المعروض'],
            'rows' => $rows,
            'warnings' => $data['acceptance_warnings'],
        ]);
    }
}
