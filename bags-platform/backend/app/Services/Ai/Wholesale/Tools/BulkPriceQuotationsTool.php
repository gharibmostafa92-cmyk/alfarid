<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\Wholesale\QuotationStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Ai\Contracts\ExecutesSelectedItems;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Wholesale\QuotationService;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\QuotationVersion;
use App\Services\Wholesale\QuoteAdvisor;

final class BulkPriceQuotationsTool implements ExecutesSelectedItems, WriteTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private QuotationService $quotations,
        private QuoteAdvisor $advisor,
        private SettingService $settings,
    ) {}

    public function name(): string
    {
        return 'bulk_price_quotations';
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
            'description' => 'يسعّر عروض الأسعار المنتظرة بخصم موحّد عن سعر القائمة. الموظف يختار العروض.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) ['discount_percent' => ['type' => 'NUMBER']],
                'required' => ['discount_percent'],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $percent = (float) ($args['discount_percent'] ?? 0);
        if ($percent < 0 || $percent > 80) {
            return ToolOutcome::denied('نسبة الخصم لازم تكون بين صفر و80.', auditable: false);
        }
        $quotes = Quotation::query()->with(['items.variant', 'customer'])->where('status', QuotationStatus::Requested)->limit(50)->get();
        $items = [];
        $plans = [];
        $expected = [];
        foreach ($quotes as $quotation) {
            $lines = [];
            foreach ($this->advisor->suggest($quotation, $actor)['lines'] as $line) {
                $price = round((float) $line['list_price'] * (1 - ($percent / 100)), 2);
                $lines[] = ['id' => $line['quotation_item_id'], 'offered_unit_price' => $price];
            }
            if ($lines === []) {
                continue;
            }
            $key = 'q'.$quotation->id;
            $items[] = ['key' => $key, 'label' => $quotation->quotation_number, 'detail' => 'خصم '.$percent.'%'];
            $plans[$key] = ['quotation_id' => $quotation->id, 'items' => $lines];
            $expected['q:'.$quotation->id] = QuotationVersion::hash($quotation);
        }
        if ($plans === []) {
            return ToolOutcome::denied('مفيش عروض منتظرة.', auditable: false);
        }
        $high = $percent > (float) $this->settings->get('ai_quote_high_risk_discount_percent', 15);

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['plans' => $plans, 'selection' => array_fill_keys(array_keys($plans), true)],
            risk: $high ? 'high' : 'medium',
            title: 'تسعير '.count($plans).' عروض',
            expectedVersions: $expected,
            typedConfirmation: $high ? 'تنفيذ' : null,
            extraPreview: ['block_type' => 'bulk_plan', 'items' => $items],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        return $this->priceSelected($actor, $action, array_keys($action->args['plans'] ?? []));
    }

    public function executeSelected(User $actor, AiPendingAction $action, array $selectedItems): ToolOutcome
    {
        $plans = $action->args['plans'] ?? [];
        if ($selectedItems === [] || array_diff($selectedItems, array_keys($plans)) !== []) {
            return ToolOutcome::denied('الاختيار فيه عرض غير متاح.', auditable: false);
        }

        return $this->priceSelected($actor, $action, $selectedItems);
    }

    /** @param  list<string>  $keys */
    private function priceSelected(User $actor, AiPendingAction $action, array $keys): ToolOutcome
    {
        $done = 0;
        foreach ($keys as $key) {
            $plan = $action->args['plans'][$key] ?? null;
            if (! is_array($plan)) {
                continue;
            }
            $quotation = Quotation::query()->find((int) $plan['quotation_id']);
            if ($quotation === null || ($action->expected_versions['q:'.$quotation->id] ?? null) !== QuotationVersion::hash($quotation)) {
                continue;
            }
            try {
                $this->quotations->price($quotation, ['items' => $plan['items'], 'priced_by' => $actor->id]);
                $done++;
            } catch (BusinessException) {
                continue;
            }
        }

        return ToolOutcome::result($done === count($keys) && $keys !== [], "اتسعّر {$done} من ".count($keys).' عروض.');
    }
}
