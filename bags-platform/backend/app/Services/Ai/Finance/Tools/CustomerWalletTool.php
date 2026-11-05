<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class CustomerWalletTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private FinancePresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'customer_wallet';
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
        return ['customers.wallet.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'رصيد محفظة عميل وآخر 20 حركة بأنواع مترجمة ومرجع كل حركة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'customer_id' => ['type' => 'INTEGER'],
                    'customer_query' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        if (! empty($args['customer_id'])) {
            $customer = Customer::find((int) $args['customer_id']);
            if (! $customer) {
                return ToolOutcome::denied('العميل مش موجود.', auditable: false);
            }
        } else {
            $query = trim((string) ($args['customer_query'] ?? ''));
            if ($query === '') {
                return ToolOutcome::denied('حدد العميل بـ customer_id أو customer_query.', auditable: false);
            }
            $options = $this->resolver->customers($actor, $query);
            if (count($options) === 0) {
                return ToolOutcome::denied('مش لاقي عميل بالوصف ده.', auditable: false);
            }
            if (count($options) > 1) {
                return ToolOutcome::denied('لقيت أكتر من عميل. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
            }
            $customer = Customer::find($options[0]['id']);
            if (! $customer) {
                return ToolOutcome::denied('العميل مش موجود.', auditable: false);
            }
        }

        $txs = CustomerWalletTransaction::query()->where('customer_id', $customer->id)
            ->orderByDesc('id')->limit(20)->get();

        $orderIds = $txs->filter(fn ($t) => in_array($t->reference_type, ['order', Order::class], true))
            ->pluck('reference_id')->unique()->values()->all();
        $orderNumbers = $orderIds === [] ? collect() : Order::query()->whereKey($orderIds)->pluck('order_number', 'id');
        $returnIds = $txs->filter(fn ($t) => in_array($t->reference_type, ['return_request', ReturnRequest::class], true))
            ->pluck('reference_id')->unique()->values()->all();
        $returnNumbers = $returnIds === [] ? collect() : ReturnRequest::query()->whereKey($returnIds)->pluck('return_number', 'id');

        $movements = $txs->map(function (CustomerWalletTransaction $t) use ($orderNumbers, $returnNumbers) {
            if (in_array($t->reference_type, ['order', Order::class], true)) {
                $refLabel = 'طلب #'.($orderNumbers[$t->reference_id] ?? $t->reference_id);
                $refLink = $this->presenter->referenceLink('order', (int) $t->reference_id);
            } elseif (in_array($t->reference_type, ['return_request', ReturnRequest::class], true)) {
                $refLabel = 'مرتجع #'.($returnNumbers[$t->reference_id] ?? $t->reference_id);
                $refLink = null;
            } else {
                $refLabel = $this->presenter->referenceLabel($t->reference_type, (int) $t->reference_id);
                $refLink = $this->presenter->referenceLink($t->reference_type, (int) $t->reference_id);
            }

            return [
                'at' => $t->created_at?->toIso8601String(),
                'type' => (string) $t->type,
                'type_label' => $this->presenter->walletTypeLabel((string) $t->type),
                'amount' => (float) $t->amount,
                'balance_after' => (float) $t->balance_after,
                'reference_label' => $refLabel,
                'reference_link' => $refLink,
            ];
        })->values()->all();

        $data = [
            'customer' => [
                'id' => $customer->id,
                'name' => $this->presenter->untrusted($customer->name, 150),
                'phone_masked' => EntityResolver::maskPhone($customer->phone),
            ],
            'current_balance' => (float) $customer->wallet_balance,
            'transactions' => $movements,
            'deep_link' => '#/dealers?customer='.$customer->id.'&tab=wallet',
        ];

        return $this->presenter->present($actor, $data, array_merge(['type' => 'wallet_card'], $data));
    }
}
