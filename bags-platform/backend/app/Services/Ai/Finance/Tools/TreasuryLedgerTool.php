<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Enums\Finance\TreasuryTransactionType;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class TreasuryLedgerTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'treasury_ledger';
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
            'description' => 'دفتر حركة خزينة بفلاتر الفترة والنوع والمرجع (حد أقصى 50 والباقي برابط).',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'treasury_id' => ['type' => 'INTEGER'],
                    'treasury_query' => ['type' => 'STRING'],
                    'from_date' => ['type' => 'STRING'],
                    'to_date' => ['type' => 'STRING'],
                    'type' => ['type' => 'STRING', 'enum' => array_column(TreasuryTransactionType::cases(), 'value')],
                    'reference_type' => ['type' => 'STRING'],
                    'reference_id' => ['type' => 'INTEGER'],
                    'cursor' => ['type' => 'INTEGER'],
                    'limit' => ['type' => 'INTEGER'],
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

        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $rows = TreasuryTransaction::query()->where('treasury_id', $treasury->id)
            ->when(! empty($args['from_date']), fn ($q) => $q->where('created_at', '>=', (string) $args['from_date'].' 00:00:00'))
            ->when(! empty($args['to_date']), fn ($q) => $q->where('created_at', '<=', (string) $args['to_date'].' 23:59:59'))
            ->when(! empty($args['type']), fn ($q) => $q->where('type', $args['type']))
            ->when(! empty($args['reference_type']), fn ($q) => $q->where('reference_type', $args['reference_type']))
            ->when(! empty($args['reference_id']), fn ($q) => $q->where('reference_id', (int) $args['reference_id']))
            ->when(! empty($args['cursor']), fn ($q) => $q->where('id', '<', (int) $args['cursor']))
            ->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(function (TreasuryTransaction $tx) {
            $txType = $tx->type instanceof \BackedEnum ? $tx->type->value : (string) $tx->type;

            return [
                'id' => $tx->id,
                'at' => $tx->created_at?->toIso8601String(),
                'type' => $txType,
                'type_label' => $this->presenter->transactionTypeLabel($txType),
                'amount' => (float) $tx->amount,
                'balance_after' => (float) $tx->balance_after,
                'reference_label' => $this->presenter->referenceLabel($tx->reference_type, $tx->reference_id),
                'reference_link' => $this->presenter->referenceLink($tx->reference_type, $tx->reference_id),
                'notes' => $tx->notes !== null ? $this->presenter->untrusted($tx->notes, 300) : null,
            ];
        })->values()->all();

        $table = array_map(fn ($i) => [
            $i['at'] ? date('Y-m-d H:i', strtotime($i['at'])) : '—',
            $i['type_label'],
            $this->presenter->money($i['amount']),
            $this->presenter->money($i['balance_after']),
            $i['reference_label'] ?? '—',
        ], $items);

        return $this->presenter->present($actor, [
            'treasury_id' => $treasury->id,
            'movements' => $items,
            'next_cursor' => $hasMore ? end($items)['id'] ?? null : null,
            'has_more' => $hasMore,
            'deep_link' => $hasMore ? $this->presenter->deepLink('tr', $treasury->id) : null,
        ], $this->presenter->table(
            ['التاريخ', 'النوع', 'المبلغ', 'الرصيد بعد', 'المرجع'],
            $table
        ));
    }
}
