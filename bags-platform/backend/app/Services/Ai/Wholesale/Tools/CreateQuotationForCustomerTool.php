<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\QuotationService;
use App\Services\SpokenQuantity;

final class CreateQuotationForCustomerTool implements WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private ProposalBuilder $proposals,
        private QuotationService $quotations,
    ) {}

    public function name(): string
    {
        return 'create_quotation_for_customer';
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
            'description' => 'ينشئ عرض سعر باسم تاجر جملة من نص الموظف، ويرسله للتسعير.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'customer_id' => ['type' => 'INTEGER'],
                    'text' => ['type' => 'STRING'],
                ],
                'required' => ['customer_id', 'text'],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $customer = Customer::query()->find((int) ($args['customer_id'] ?? 0));
        if (! $customer?->isWholesaleApproved()) {
            return ToolOutcome::denied('التاجر لازم يكون جملة أو موزع ومعتمد.', auditable: false);
        }
        $parsed = $this->parseLines($actor, (string) ($args['text'] ?? ''));
        if ($parsed instanceof ToolOutcome) {
            return $parsed;
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['customer_id' => $customer->id, 'items' => $parsed],
            title: 'عرض سعر لـ '.($customer->company_name ?: $customer->name),
            after: ['items' => $parsed],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $customer = Customer::query()->findOrFail((int) $action->args['customer_id']);
        try {
            $draft = $this->quotations->create($customer, [
                'source' => 'admin',
                'items' => $action->args['items'],
            ]);
            $submitted = $this->quotations->submit($draft);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }

        return ToolOutcome::result(true, 'اتعمل العرض '.$submitted->quotation_number, [
            'quotation_id' => $submitted->id,
        ]);
    }

    /** @return list<array{product_variant_id: int, qty: float}>|ToolOutcome */
    private function parseLines(User $actor, string $text): array|ToolOutcome
    {
        $parts = preg_split('/\s*(?:,|،|\n| و )\s*/u', trim($text)) ?: [];
        $items = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $qty = SpokenQuantity::parse($part) ?? 1.0;
            $query = trim((string) preg_replace('/\d+(\.\d+)?/u', '', $part));
            $matches = $this->resolver->variants($actor, $query);
            if (count($matches) !== 1) {
                return ToolOutcome::picker([
                    'type' => 'entity_picker',
                    'field' => 'text',
                    'purpose' => 'السطر "'.$part.'" محتاج صنف واحد واضح',
                    'options' => array_slice($matches, 0, 8),
                ]);
            }
            $items[] = ['product_variant_id' => $matches[0]['id'], 'qty' => $qty];
        }
        if ($items === []) {
            return ToolOutcome::denied('اكتب الأصناف والكميات.', auditable: false);
        }

        return $items;
    }
}
