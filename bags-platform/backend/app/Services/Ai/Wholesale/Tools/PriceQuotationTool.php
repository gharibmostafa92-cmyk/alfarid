<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Shared\CostAccess;
use App\Services\Wholesale\QuotationService;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\QuotationVersion;
use App\Services\Wholesale\QuoteAdvisor;

final class PriceQuotationTool implements LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private QuotationService $quotations,
        private QuoteAdvisor $advisor,
        private SettingService $settings,
    ) {}

    public function name(): string
    {
        return 'price_quotation';
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
            'description' => 'يسعّر عرض سعر مُرسل. لو الخصم أو الإجمالي عالي، التأكيد بكتابة رقم العرض.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'quotation_id' => ['type' => 'INTEGER'],
                    'use_suggested' => ['type' => 'BOOLEAN'],
                    'valid_until' => ['type' => 'STRING'],
                    'items' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => (object) ['id' => ['type' => 'INTEGER'], 'offered_unit_price' => ['type' => 'NUMBER']], 'required' => ['id', 'offered_unit_price']]],
                ],
                'required' => ['quotation_id'],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $quotation = Quotation::query()->with(['items.variant', 'customer'])->find((int) ($args['quotation_id'] ?? 0));
        if ($quotation === null) {
            return ToolOutcome::denied('عرض السعر مش موجود.', auditable: false);
        }
        $suggestion = $this->advisor->suggest($quotation, $actor);
        $byId = [];
        foreach ($args['items'] ?? [] as $row) {
            if (is_array($row) && isset($row['id'])) {
                $byId[(int) $row['id']] = round((float) $row['offered_unit_price'], 2);
            }
        }
        $useSuggested = (bool) ($args['use_suggested'] ?? $byId === []);
        $priced = [];
        $maxDiscount = 0.0;
        $total = 0.0;
        $belowCost = false;
        $policy = (string) $this->settings->get('ai_price_below_cost_policy', 'block');
        foreach ($suggestion['lines'] as $line) {
            $id = (int) $line['quotation_item_id'];
            $price = $byId[$id] ?? ($useSuggested ? (float) $line['suggested_price'] : null);
            if ($price === null) {
                return ToolOutcome::denied('حدد سعر كل بند أو اطلب التسعير المقترح.', auditable: false);
            }
            $list = (float) $line['list_price'];
            if ($list > 0) {
                $maxDiscount = max($maxDiscount, (($list - $price) / $list) * 100);
            }
            $item = $quotation->items->firstWhere('id', $id);
            $qty = (float) ($item->qty ?? 0);
            $total += $price * $qty;
            $cost = $item?->variant?->cost_price;
            if ($cost !== null && $price + 0.001 < (float) $cost) {
                if ($policy === 'block') {
                    return ToolOutcome::denied('السعر أقل من التكلفة والسياسة تمنع ذلك.', auditable: false);
                }
                $belowCost = true;
            }
            $priced[] = ['id' => $id, 'offered_unit_price' => $price];
        }
        $discountCap = (float) $this->settings->get('ai_quote_high_risk_discount_percent', 15);
        $whatsappCap = (float) $this->settings->get('ai_whatsapp_quote_max_discount_percent', 5);
        if ($this->proposals->conversation()?->channel === 'whatsapp' && $maxDiscount > $whatsappCap) {
            return ToolOutcome::denied('خصم عرض السعر أعلى من المسموح على واتساب.', auditable: false);
        }
        $high = $belowCost
            || $maxDiscount > $discountCap
            || $total > (float) $this->settings->get('ai_quote_high_risk_total', 100000);
        $snapshot = [
            'valid_until' => $quotation->valid_until?->toDateString(),
            'discount_amount' => (string) $quotation->discount_amount,
            'tax_amount' => (string) $quotation->tax_amount,
            'items' => $quotation->items->map(fn ($item) => [
                'id' => $item->id,
                'offered_unit_price' => (string) $item->offered_unit_price,
            ])->values()->all(),
        ];
        $previewLines = CostAccess::canView($actor) ? $suggestion['lines'] : CostAccess::strip($suggestion['lines']);

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'quotation_id' => $quotation->id,
                'valid_until' => $args['valid_until'] ?? $quotation->valid_until?->toDateString(),
                'items' => $priced,
            ],
            risk: $high ? 'high' : 'medium',
            title: 'تسعير '.$quotation->quotation_number,
            before: ['status' => $quotation->status->value],
            after: ['lines' => $priced, 'estimated_total' => round($total, 2)],
            warnings: $this->quotations->acceptanceWarnings($quotation),
            expectedVersions: ['q:'.$quotation->id => QuotationVersion::hash($quotation)],
            inverse: $snapshot,
            typedConfirmation: $high ? $quotation->quotation_number : null,
            typedConfirmationHint: $high ? 'اكتب رقم عرض السعر للتأكيد' : null,
            extraPreview: ['block_type' => 'price_diff', 'lines' => $previewLines],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        Quotation::query()->lockForUpdate()->findOrFail((int) $action->args['quotation_id']);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $quotation = Quotation::query()->findOrFail((int) $action->args['quotation_id']);
        $expected = $action->expected_versions['q:'.$quotation->id] ?? null;
        if ($expected !== QuotationVersion::hash($quotation)) {
            return ToolOutcome::denied('عرض السعر اتغير بعد الاقتراح. اعمل الاقتراح من جديد.', auditable: false);
        }
        try {
            $priced = $this->quotations->price($quotation, [
                'items' => $action->args['items'],
                'valid_until' => $action->args['valid_until'] ?? null,
                'priced_by' => $actor->id,
            ]);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }

        return ToolOutcome::result(true, 'اتسعّر '.$priced->quotation_number, [
            'quotation_id' => $priced->id,
            'grand_total' => (string) $priced->grand_total,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        $quotation = Quotation::query()->find((int) ($action->args['quotation_id'] ?? 0));

        return $quotation !== null && $quotation->status->value === 'priced_by_admin';
    }

    public function targetState(AiPendingAction $action): string
    {
        $quotation = Quotation::query()->findOrFail((int) $action->args['quotation_id']);

        return QuotationVersion::hash($quotation);
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $quotation = Quotation::query()->findOrFail((int) $action->args['quotation_id']);
        $inverse = $action->inverse;
        if (! is_array($inverse)) {
            return ToolOutcome::denied('مفيش نسخة ترجع لها.', auditable: false);
        }
        try {
            $this->quotations->restorePricedSnapshot($quotation, $inverse);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }

        return ToolOutcome::result(true, 'اتلغى التسعير ورجع العرض للطلب.');
    }
}
