<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class ExplainTreasuryBalanceTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'explain_treasury_balance';
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
        return ['treasuries.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'الرصيد ده جه منين: رصيد بداية الفترة ثم تجميع حسب النوع ثم رصيد النهاية ومقارنته بالرصيد الحالي.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'treasury_id' => ['type' => 'INTEGER'],
                    'treasury_query' => ['type' => 'STRING'],
                    'from_date' => ['type' => 'STRING'],
                    'to_date' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        $resolved = FinanceArgs::resolveTreasury($actor, $this->resolver, $this->branches, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $treasury = $resolved['treasury'];

        $to = ! empty($args['to_date']) ? (string) $args['to_date'] : now(config('app.business_timezone', 'Africa/Cairo'))->toDateString();
        $from = ! empty($args['from_date']) ? (string) $args['from_date'] : now(config('app.business_timezone', 'Africa/Cairo'))->startOfMonth()->toDateString();

        $openingTx = TreasuryTransaction::query()->where('treasury_id', $treasury->id)
            ->where('created_at', '<', $from.' 00:00:00')
            ->orderByDesc('id')->first(['balance_after']);
        $opening = $openingTx ? (float) $openingTx->balance_after : 0.0;

        $grouped = TreasuryTransaction::query()->where('treasury_id', $treasury->id)
            ->where('created_at', '>=', $from.' 00:00:00')
            ->where('created_at', '<=', $to.' 23:59:59')
            ->selectRaw('type, COUNT(*) AS movements_count, SUM(amount) AS total')
            ->groupBy('type')->orderBy('type')->get();

        $groups = [];
        $periodTotal = 0.0;
        foreach ($grouped as $row) {
            $type = $row->type instanceof \BackedEnum ? $row->type->value : (string) $row->type;
            $total = (float) $row->total;
            $periodTotal += $total;
            $groups[] = [
                'type' => $this->presenter->transactionTypeLabel($type),
                'count' => (int) $row->movements_count,
                'quantity_total' => $total,
            ];
        }

        $ending = round($opening + $periodTotal, 2);
        $current = (float) $treasury->current_balance;
        $isCurrentPeriod = $to === now(config('app.business_timezone', 'Africa/Cairo'))->toDateString();

        $timeline = TreasuryTransaction::query()->where('treasury_id', $treasury->id)
            ->where('created_at', '>=', $from.' 00:00:00')
            ->where('created_at', '<=', $to.' 23:59:59')
            ->orderByDesc('id')->limit(20)->get()
            ->map(function (TreasuryTransaction $tx) {
                $txType = $tx->type instanceof \BackedEnum ? $tx->type->value : (string) $tx->type;

                return [
                    'id' => $tx->id,
                    'created_at' => $tx->created_at?->toIso8601String(),
                    'type' => $this->presenter->transactionTypeLabel($txType),
                    'quantity' => (float) $tx->amount,
                    'balance_after' => (float) $tx->balance_after,
                    'reference_number' => $this->presenter->referenceLabel($tx->reference_type, $tx->reference_id),
                    'reference_link' => $this->presenter->referenceLink($tx->reference_type, $tx->reference_id),
                ];
            })->values()->all();

        return $this->presenter->present($actor, [
            'treasury_id' => $treasury->id,
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'ending_balance' => $ending,
            'current_balance' => $current,
            'matches_current' => $isCurrentPeriod ? abs($ending - $current) < 0.005 : null,
            'difference' => round($current - $ending, 2),
        ], [
            'type' => 'balance_story',
            'start_balance' => $opening,
            'groups' => $groups,
            'timeline' => $timeline,
        ]);
    }
}
