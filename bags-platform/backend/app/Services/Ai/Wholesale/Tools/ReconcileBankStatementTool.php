<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Wholesale\BankStatementMatcher;

final class ReconcileBankStatementTool implements AgentTool
{
    public function __construct(private BankStatementMatcher $matcher) {}

    public function name(): string
    {
        return 'reconcile_bank_statement';
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
        return ['payment_notices.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'يطابق حركات دائنة من كشف بنك مع إشعارات معلقة: مطابق، محتمل، ومن غير إشعار. لا يعتمد شيئًا.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) ['rows' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => (object) ['amount' => ['type' => 'NUMBER'], 'reference' => ['type' => 'STRING'], 'date' => ['type' => 'STRING'], 'description' => ['type' => 'STRING']]]]],
                'required' => ['rows'],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $rows = $args['rows'] ?? [];
        if (! is_array($rows) || $rows === []) {
            return ToolOutcome::denied('ارفع صفوف الكشف: مبلغ ومرجع وتاريخ ووصف.', auditable: false);
        }
        $matched = $this->matcher->match($rows);
        foreach ($matched['unmatched'] as &$row) {
            if (isset($row['suggestion']['name'])) {
                $row['draft'] = 'وصلنا '.($row['amount'] ?? '').'، نسجله على الحساب؟';
            }
        }

        return ToolOutcome::present($matched, ['type' => 'table', 'rows' => $matched], 'نتيجة مطابقة الكشف');
    }
}
