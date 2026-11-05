<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory;

use App\Models\User;
use App\Services\Ai\ToolOutcome;
use App\Services\CostAccess;
use App\Services\SettingService;

final class InventoryPresenter
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
        // طبقتان لحماية التكلفة: بلا صلاحية عرض تُحذف من كل مكان،
        // ومع الصلاحية تبقى الأرقام في كارت العرض فقط ولا تصل للنموذج.
        if (! CostAccess::canView($actor)) {
            $data = $this->stripCost($data);
            if ($uiBlock !== null) {
                $uiBlock = $this->stripCost($uiBlock);
            }
        } elseif (! $this->settings->getBool('ai_inventory_share_cost_with_model', false)) {
            $data = $this->maskActuals($data);
            $data['cost_hidden_from_model'] = true;
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

    private const COST_KEYS = ['cost_price', 'cost', 'unit_cost', 'total_value', 'tied_value', 'value_impact', 'cost_before', 'cost_after'];

    /** @return array<string,mixed> */
    public function stripCost(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, self::COST_KEYS, true)) {
                continue;
            }
            $out[$key] = is_array($value) ? $this->stripCost($value) : $value;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function maskActuals(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, self::COST_KEYS, true)) {
                $out[$key] = null;

                continue;
            }
            $out[$key] = is_array($value) ? $this->maskActuals($value) : $value;
        }

        return $out;
    }
}
