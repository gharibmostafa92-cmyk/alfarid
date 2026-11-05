<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing;

use App\Models\User;
use App\Services\Ai\ToolOutcome;
use App\Services\CostAccess;
use App\Services\SettingService;

final class PricingPresenter
{
    public function __construct(private SettingService $settings) {}

    /** @return array{untrusted_text: string} */
    public function untrusted(?string $value, int $max = 300): array
    {
        $clean = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value), 0, $max);

        return ['untrusted_text' => $clean];
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

        $maxChars = max(1024, (int) $this->settings->get('ai_tool_result_max_chars', 16000));
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($encoded !== false && strlen($encoded) > $maxChars) {
            return ToolOutcome::data([
                'truncated' => true,
                'message' => 'ضيّق البحث',
            ], $uiBlock, 'ضيّق البحث');
        }

        return ToolOutcome::data($data, $uiBlock, $message);
    }

    /** @return array<string,mixed> */
    public function stripCost(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if ($key === 'cost_price' || $key === 'cost') {
                continue;
            }
            $out[$key] = is_array($value) ? $this->stripCost($value) : $value;
        }

        return $out;
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
