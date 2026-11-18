<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\Wholesale\WholesaleAccessGuard;
use App\Services\Inventory\BranchAccess;
use App\Services\Wholesale\BankStatementMatcher;

final class ReconcileBankStatementTool implements AgentTool
{
    public function __construct(private BankStatementMatcher $matcher, private BranchAccess $branches) {}

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
        if ($denied = WholesaleAccessGuard::denyIfNoCentralAccess($this->branches, $actor)) {
            return $denied;
        }
        $rows = $args['rows'] ?? [];
        if (! is_array($rows) || $rows === []) {
            return ToolOutcome::denied('ارفع صفوف الكشف: مبلغ ومرجع وتاريخ ووصف.', auditable: false);
        }
        if (count($rows) > 500) {
            return ToolOutcome::denied('كشف البنك يتجاوز 500 حركة؛ قسّمه إلى ملفات أصغر.', auditable: false);
        }
        $rows = array_values(array_map(static function ($row): array {
            if (! is_array($row)) {
                return [];
            }
            $row['reference'] = ToolOutcome::untrusted(isset($row['reference']) ? (string) $row['reference'] : null, 200)['untrusted_text'];
            $row['description'] = ToolOutcome::untrusted(isset($row['description']) ? (string) $row['description'] : null, 500)['untrusted_text'];

            return $row;
        }, $rows));
        $matched = $this->matcher->match($rows);
        foreach ($matched['unmatched'] as &$row) {
            if (isset($row['suggestion']['name'])) {
                $row['draft'] = 'وصلنا '.($row['amount'] ?? '').'، نسجله على الحساب؟';
            }
        }
        unset($row);

        $tableRows = [];
        foreach (['exact' => 'مطابق', 'probable' => 'محتمل', 'unmatched' => 'بدون إشعار'] as $bucket => $label) {
            foreach ((array) ($matched[$bucket] ?? []) as $index => $entry) {
                $rawReference = (string) ($entry['reference'] ?? '');
                $entry['reference'] = ToolOutcome::untrusted($rawReference, 200);
                $matched[$bucket][$index] = $entry;
                $tableRows[] = [
                    $label,
                    (string) ($entry['amount'] ?? ''),
                    $rawReference,
                    (string) ($entry['date'] ?? ''),
                    (string) ($entry['notice_number'] ?? ($entry['suggestion']['name'] ?? '')),
                ];
            }
        }

        return ToolOutcome::present($matched, [
            'type' => 'table',
            'columns' => ['الحالة', 'المبلغ', 'المرجع', 'التاريخ', 'الإشعار / المقترح'],
            'rows' => $tableRows,
        ], 'نتيجة مطابقة الكشف');
    }
}
