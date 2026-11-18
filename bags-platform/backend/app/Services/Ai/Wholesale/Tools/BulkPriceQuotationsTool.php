<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\Wholesale\QuotationStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Ai\Contracts\ExecutesSelectedItems;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\Wholesale\WholesaleAccessGuard;
use App\Services\Ai\WriteTool;
use App\Services\Inventory\BranchAccess;
use App\Services\Wholesale\QuotationService;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\QuotationVersion;
use App\Services\Wholesale\QuoteAdvisor;
use Illuminate\Support\Facades\DB;

final class BulkPriceQuotationsTool implements ExecutesSelectedItems, LocksTargets, WriteTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private QuotationService $quotations,
        private QuoteAdvisor $advisor,
        private SettingService $settings,
        private BranchAccess $branches,
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
        if ($denied = WholesaleAccessGuard::denyIfNoCentralAccess($this->branches, $actor)) {
            return $denied;
        }
        $percent = (float) ($args['discount_percent'] ?? 0);
        if ($percent < 0 || $percent > 80) {
            return ToolOutcome::denied('نسبة الخصم لازم تكون بين صفر و80.', auditable: false);
        }
        $quotes = Quotation::query()->with(['items.variant', 'customer'])->where('status', QuotationStatus::Requested)->limit(50)->get();
        $items = [];
        $plans = [];
        $expected = [];
        $warnings = [];
        foreach ($quotes as $quotation) {
            $lines = [];
            $invalid = false;
            $invalidReason = null;
            try {
                $suggestedLines = $this->advisor->suggest($quotation, $actor)['lines'];
            } catch (BusinessException) {
                $warnings[] = "تم استبعاد {$quotation->quotation_number} لغياب سعر القائمة المرجعي لبعض البنود.";
                continue;
            }
            $itemsById = $quotation->items->keyBy('id');
            foreach ($suggestedLines as $line) {
                if ($line['list_price'] === null || (float) $line['list_price'] <= 0) {
                    $invalid = true;
                    $invalidReason = 'غياب سعر القائمة المرجعي لبعض البنود';
                    break;
                }
                $price = round((float) $line['list_price'] * (1 - ($percent / 100)), 2);
                if ($price <= 0) {
                    $invalid = true;
                    $invalidReason = 'السعر المقترح غير موجب';
                    break;
                }
                $cost = $itemsById->get((int) $line['quotation_item_id'])?->variant?->cost_price;
                if ($cost !== null && $price + 0.001 < (float) $cost) {
                    $invalid = true;
                    $invalidReason = 'السعر بعد الخصم أقل من التكلفة';
                    break;
                }
                $lines[] = ['id' => $line['quotation_item_id'], 'offered_unit_price' => $price];
            }
            if ($invalid) {
                $warnings[] = "تم استبعاد {$quotation->quotation_number}: {$invalidReason}. راجعه يدويًا.";
                continue;
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
            return ToolOutcome::denied($warnings !== [] ? implode(' ', $warnings) : 'مفيش عروض منتظرة.', auditable: false);
        }
        $high = $percent > (float) $this->settings->get('ai_quote_high_risk_discount_percent', 15);

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['plans' => $plans, 'selection' => array_fill_keys(array_keys($plans), true)],
            risk: $high ? 'high' : 'medium',
            title: 'تسعير '.count($plans).' عروض',
            warnings: $warnings,
            expectedVersions: $expected,
            typedConfirmation: $high ? 'تنفيذ' : null,
            extraPreview: ['block_type' => 'bulk_plan', 'items' => $items],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        $ids = [];
        foreach ((array) ($action->args['plans'] ?? []) as $plan) {
            if (is_array($plan) && isset($plan['quotation_id'])) {
                $ids[] = (int) $plan['quotation_id'];
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        if ($ids !== []) {
            Quotation::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        }
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
        if ($denied = WholesaleAccessGuard::denyIfNoCentralAccess($this->branches, $actor)) {
            return $denied;
        }
        $done = 0;
        foreach ($keys as $key) {
            $plan = $action->args['plans'][$key] ?? null;
            if (! is_array($plan)) {
                continue;
            }
            $ok = DB::transaction(function () use ($actor, $action, $plan): bool {
                $quotation = Quotation::query()->lockForUpdate()->find((int) $plan['quotation_id']);
                if ($quotation === null || ($action->expected_versions['q:'.$quotation->id] ?? null) !== QuotationVersion::hash($quotation)) {
                    return false;
                }
                try {
                    $this->quotations->price($quotation, ['items' => $plan['items'], 'priced_by' => $actor->id]);

                    return true;
                } catch (BusinessException) {
                    return false;
                }
            });
            if ($ok) {
                $done++;
            }
        }

        return ToolOutcome::result($done === count($keys) && $keys !== [], "اتسعّر {$done} من ".count($keys).' عروض.');
    }
}
