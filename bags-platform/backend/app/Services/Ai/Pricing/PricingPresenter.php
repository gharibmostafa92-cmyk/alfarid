<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing;

use App\Models\User;
use App\Services\Ai\ToolOutcome;
use App\Services\Shared\CostAccess;
use App\Services\Shared\SettingService;

final class PricingPresenter
{
    public function __construct(private SettingService $settings) {}

    /** @return array{untrusted_text: string} */
    public function untrusted(?string $value, int $max = 300): array
    {
        return ToolOutcome::untrusted($value, $max);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $uiBlock
     */
    public function present(User $actor, array $data, ?array $uiBlock = null, ?string $message = null): ToolOutcome
    {
        if (! CostAccess::canView($actor)) {
            $data = $this->stripCost($data);
            if ($uiBlock !== null) {
                $uiBlock = $this->stripCost($uiBlock);
            }
        }

        return ToolOutcome::present($data, $uiBlock, $message, (int) $this->settings->get('ai_tool_result_max_chars', 16000));
    }

    /** @return array<string,mixed> */
    public function stripCost(array $payload): array
    {
        return CostAccess::strip($payload);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $rows
     * @return array<string,mixed>
     */
    public function table(array $columns, array $rows): array
    {
        return ['type' => 'table', 'columns' => $columns, 'rows' => $rows];
    }

    /**
     * @param  list<array{key:string,label:string,severity:string,detail?:string,count?:int}>  $items
     * @return array<string,mixed>
     */
    public function checklist(array $items): array
    {
        return ['type' => 'checklist', 'items' => $items];
    }
}
