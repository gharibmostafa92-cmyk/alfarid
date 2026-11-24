<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance;

use App\Models\User;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ToolOutcome;
use App\Services\Shared\SettingService;

final class FinancePresenter
{
    public const MONEY_KEYS = [
        'current_balance', 'balance_after', 'balance_before', 'projected_balance', 'total_in', 'total_out', 'net',
        'from_balance', 'to_balance', 'opening_balance', 'ending_balance', 'difference', 'wallet_balance',
        'current', 'projected', 'cash', 'bank', 'coverage_days',
        'reserved', 'shortfall', 'excess', 'available', 'cash_sales', 'shortage', 'surplus',
        'balance', 'amount', 'total', 'previous_total', 'discount_total', 'grand_total',
        'debt', 'linked_open_notices', 'free_pool', 'allocated', 'allocated_open', 'floating_credit',
        '0_30', '31_60', '61_90', 'over_90',
        'credit_limit', 'used_credit', 'available_credit', 'outstanding', 'credit_balance', 'aging',
        'expenses', 'daily_expense_avg', 'pending_in', 'pending_out', 'expected_in', 'expected_out',
        'paid_amount', 'amount_due', 'total_amount', 'order_total', 'subtotal', 'discount_amount',
        'tax_amount', 'unit_price', 'list_price', 'offered_unit_price', 'requested_target_price',
        'line_total', 'debit', 'credit', 'income', 'revenue', 'sales', 'sales_total',
    ];

    public function __construct(private SettingService $settings) {}

    /** @return array{untrusted_text: string} */
    public function untrusted(?string $value, int $max = 300): array
    {
        return ToolOutcome::untrusted($value, $max);
    }

    public function money(string|float|int $v): string
    {
        return number_format((float) $v, 2, '.', ',').' ج.م';
    }

    public function deepLink(string $tab, ?int $id = null): string
    {
        return '#/finance?tab='.$tab.($id !== null ? '&id='.$id : '');
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $uiBlock
     */
    public function present(User $actor, array $data, ?array $uiBlock = null, ?string $message = null): ToolOutcome
    {
        // Balances stay in the UI card but never reach the model when sharing is off.
        if (! $this->settings->getBool('ai_finance_share_balances_with_model', true)) {
            $data = $this->maskBalances($data);
            $data['balances_hidden_from_model'] = true;
        }

        return ToolOutcome::present($data, $uiBlock, $message, (int) $this->settings->get('ai_tool_result_max_chars', 16000));
    }

    /** @return array<string,mixed> */
    private function maskBalances(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, self::MONEY_KEYS, true)) {
                $out[$key] = null;

                continue;
            }
            if ($key === 'prompt' && is_string($value) && str_starts_with($value, 'حوّل ')) {
                $out[$key] = (string) preg_replace('/^حوّل\s+[0-9.,]+\s+/', 'حوّل المبلغ المطلوب ', $value);

                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->maskBalances($value);

                continue;
            }
            if (is_string($value)) {
                $value = (string) preg_replace('/(?<![\p{L}\p{N}])[0-9][0-9,]*(?:\.[0-9]{1,2})?\s*ج\.م/u', 'المبلغ مخفي', $value);
                $value = (string) preg_replace('/((?:مبلغ|متوسط|الرصيد|رصيد|عجز|زيادة|إجمالي|المبيعات|مصروفات?|فيها|حوّل)\s*)[0-9][0-9,]*(?:\.[0-9]+)?/u', '$1المبلغ مخفي', $value);
                if ($key === 'label') {
                    $value = (string) preg_replace('/(راجع تحويل فائض الأدراج\s*)\([^)]*\)/u', '$1(المبلغ مخفي)', $value);
                }
            }
            $out[$key] = $value;
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

    public function treasuryTypeLabel(string $type): string
    {
        return EntityResolver::treasuryTypeLabel($type);
    }

    public function transactionTypeLabel(string $type): string
    {
        return match ($type) {
            'opening_balance' => 'رصيد افتتاحي',
            'pos_sale' => 'مبيعات POS',
            'pos_return' => 'مرتجع POS',
            'order_refund' => 'استرداد طلب',
            'expense' => 'مصروف',
            'expense_reversal' => 'عكس مصروف',
            'transfer_in' => 'تحويل وارد',
            'transfer_out' => 'تحويل صادر',
            'supplier_payment' => 'مدفوعة مورد',
            'customer_receipt' => 'مقبوض عميل',
            'cash_shortage' => 'عجز',
            'cash_overage' => 'زيادة',
            default => $type,
        };
    }

    public function referenceLabel(?string $type, ?int $id): ?string
    {
        if ($type === null || $type === '' || $id === null) {
            return null;
        }
        $base = mb_strtolower((string) preg_replace('/^.*\\\\/', '', $type));

        return match ($base) {
            'expense' => 'مصروف',
            'treasurytransfer' => 'تحويل خزينة',
            'treasurytransaction' => 'حركة خزينة',
            'treasury' => 'خزينة',
            'order' => 'طلب',
            'returnrequest' => 'مرتجع',
            'posshift' => 'وردية',
            'customer' => 'عميل',
            'walletadjustment' => 'تعديل محفظة',
            default => $type,
        }.' #'.$id;
    }

    public function referenceLink(?string $type, ?int $id): ?string
    {
        if ($type === null || $type === '' || $id === null) {
            return null;
        }
        $base = mb_strtolower((string) preg_replace('/^.*\\\\/', '', $type));

        return match ($base) {
            'expense' => $this->deepLink('exp', $id),
            'treasurytransfer' => $this->deepLink('trf', $id),
            'treasury' => $this->deepLink('tr', $id),
            'order' => '#/orders/'.$id,
            default => null,
        };
    }

    public function walletTypeLabel(string $type): string
    {
        return match ($type) {
            'checkout_payment' => 'دفع طلب',
            'checkout_refund' => 'استرداد طلب',
            'order_reversal' => 'عكس دفع طلب',
            'return_refund' => 'استرداد مرتجع',
            'refund' => 'استرداد',
            'refund_reversal' => 'عكس استرداد',
            'adjustment' => 'تعديل من الإدارة',
            'topup' => 'شحن المحفظة',
            'opening' => 'رصيد افتتاحي',
            default => $type,
        };
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
