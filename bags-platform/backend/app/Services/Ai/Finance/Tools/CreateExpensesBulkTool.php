<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Tools;

use App\Services\Pos\TreasuryService;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\ExecutesSelectedItems;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Ai\Finance\FinanceWriteGuards;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\ExpenseService;
use App\Services\Shared\SettingService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateExpensesBulkTool implements AgentTool, ExecutesSelectedItems, LocksTargets, WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private FinancePresenter $presenter,
        private ProposalBuilder $proposals,
        private ExpenseService $expenses,
        private SettingService $settings,
    ) {}

    public function name(): string
    {
        return 'create_expenses_bulk';
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
        return ['expenses.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تسجيل عدة مصروفات دفعة واحدة (صفوف منظمة أو CSV). كل صف يُسجَّل لوحده والفاشل يُتخطى بتقرير.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'rows' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => (object) [
                        'branch_id' => ['type' => 'INTEGER'],
                        'treasury_id' => ['type' => 'INTEGER'],
                        'treasury_query' => ['type' => 'STRING'],
                        'category' => ['type' => 'STRING'],
                        'amount' => ['type' => 'NUMBER'],
                        'expense_date' => ['type' => 'STRING'],
                        'notes' => ['type' => 'STRING'],
                        'key' => ['type' => 'STRING'],
                    ]]],
                    'csv' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = FinanceArgs::normalizeDigitsInArray($args);
        $rawRows = is_array($args['rows'] ?? null) ? array_values($args['rows']) : [];
        if ($rawRows === [] && isset($args['csv'])) {
            $rawRows = $this->parseCsv((string) $args['csv']);
        }
        $maxItems = max(1, min(100, (int) $this->settings->get('ai_bulk_max_items', 25)));
        if ($rawRows === [] || count($rawRows) > $maxItems) {
            return ToolOutcome::denied("ابعت من صف لـ {$maxItems} صفًا.", auditable: false);
        }

        $valid = [];
        $lineErrors = [];
        $items = [];
        foreach ($rawRows as $index => $raw) {
            $line = $index + 1;
            $row = is_array($raw) ? FinanceArgs::normalizeDigitsInArray($raw) : [];
            $check = $this->validateRow($actor, $row);
            $key = (string) Str::ulid();
            if ($check instanceof ToolOutcome) {
                $lineErrors[] = ['line' => $line, 'error' => $check->message];
                $items[] = ['key' => $key, 'label' => "سطر {$line}", 'ok' => false, 'message' => $check->message];

                continue;
            }
            $check['key'] = 'expense:bulk:'.sha1(json_encode($check, JSON_UNESCAPED_UNICODE).'#'.$index);
            $check['selection_key'] = $key;
            $check['line'] = $line;
            $valid[] = $check;
            $items[] = ['key' => $key, 'label' => "سطر {$line} · {$check['category']}",
                'change' => $this->presenter->money($check['amount']), 'after' => ['treasury_id' => $check['treasury_id'], 'expense_date' => $check['expense_date']]];
        }
        if ($valid === []) {
            return ToolOutcome::denied('مفيش صفوف صالحة للتسجيل.', auditable: false);
        }
        $total = array_sum(array_column($valid, 'amount'));
        $threshold = (float) $this->settings->get('ai_finance_high_risk_amount', 20000);
        $risk = $total > $threshold ? 'high' : $this->risk();
        $versions = Treasury::query()->whereKey(array_unique(array_column($valid, 'treasury_id')))->get()
            ->mapWithKeys(fn (Treasury $treasury) => ['treasury:'.$treasury->id => FinanceWriteGuards::ledgerVersion($treasury)])->all();

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['rows' => $valid],
            risk: $risk,
            title: 'تسجيل '.count($valid).' مصروفات دفعة واحدة',
            before: ['valid_rows' => count($valid), 'total' => $total],
            after: ['valid_rows' => count($valid), 'total' => $total],
            warnings: array_merge(
                ['كل صف يُسجَّل كمصروف مستقل بمفتاح عدم تكرار خاص به؛ راجع الصفوف قبل التأكيد.'],
                array_map(fn ($e) => "سطر {$e['line']}: {$e['error']}", $lineErrors)
            ),
            expectedVersions: $versions,
            typedConfirmation: $risk === 'high' ? 'تنفيذ' : null,
            extraPreview: ['block_type' => 'bulk_plan', 'items' => $items],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        $ids = [];
        foreach ((array) ($action->args['rows'] ?? []) as $row) {
            if (isset($row['treasury_id'])) {
                $ids[] = (int) $row['treasury_id'];
            }
        }
        if ($ids !== []) {
            app(\App\Services\Pos\TreasuryService::class)->lockTreasuries(array_values(array_unique($ids)), $action->user_id);
        }
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        return $this->createRows($actor, $action, $action->args['rows'] ?? []);
    }

    public function executeSelected(User $actor, AiPendingAction $action, array $selectedItems): ToolOutcome
    {
        $rows = array_column($action->args['rows'] ?? [], null, 'selection_key');
        abort_if($selectedItems === [] || array_diff($selectedItems, array_keys($rows)) !== [], 422, 'الاختيار فيه مصروف غير متاح.');

        return $this->createRows($actor, $action, array_values(array_intersect_key($rows, array_flip($selectedItems))));
    }

    private function createRows(User $actor, AiPendingAction $action, array $rows): ToolOutcome
    {
        $treasuries = Treasury::query()->whereKey(array_unique(array_column($rows, 'treasury_id')))->get();
        foreach ($treasuries as $treasury) {
            if (($action->expected_versions['treasury:'.$treasury->id] ?? null) !== FinanceWriteGuards::ledgerVersion($treasury)) {
                throw new BusinessException('البيانات اتغيرت بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
            }
        }
        $items = [];
        $succeeded = 0;
        foreach ($rows as $row) {
            try {
                $expense = $this->expenses->create([
                    'branch_id' => (int) $row['branch_id'], 'treasury_id' => (int) $row['treasury_id'],
                    'category' => (string) $row['category'], 'amount' => (string) $row['amount'],
                    'expense_date' => (string) $row['expense_date'], 'notes' => $row['notes'] ?? null,
                ], $actor, (string) $row['key']);
                $succeeded++;
                $items[] = ['line' => $row['line'] ?? null, 'ok' => true,
                    'message' => "اتسجل {$expense->expense_number}.", 'expense_id' => $expense->id];
            } catch (BusinessException|ValidationException $e) {
                $message = $e instanceof ValidationException
                    ? collect($e->errors())->flatten()->implode(' ')
                    : $e->getMessage();
                $items[] = ['line' => $row['line'] ?? null, 'ok' => false, 'message' => $message ?: 'تعذّر تسجيل الصف.'];
            }
        }
        $ok = $succeeded === count($rows) && $succeeded > 0;

        return ToolOutcome::result($ok, "اتسجل {$succeeded} من ".count($rows).' مصروفات.', [
            'items' => $items,
            'deep_link' => $this->presenter->deepLink('exp'),
        ]);
    }

    /** @return array<string,mixed>|ToolOutcome Validated row or denial carrying the line error */
    private function validateRow(User $actor, array $row): array|ToolOutcome
    {
        $resolved = FinanceArgs::resolveTreasury($actor, $this->resolver, $this->branches, [
            'treasury_id' => $row['treasury_id'] ?? null,
            'treasury_query' => $row['treasury_query'] ?? '',
        ]);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $treasury = $resolved['treasury'];
        $branchId = isset($row['branch_id']) ? (int) $row['branch_id'] : $treasury->branch_id;
        if ($branchId === null) {
            return ToolOutcome::denied('حدد الفرع.', auditable: false);
        }
        $category = mb_substr(trim((string) ($row['category'] ?? '')), 0, 191);
        if ($category === '') {
            return ToolOutcome::denied('فئة المصروف مطلوبة.', auditable: false);
        }
        try {
            $amount = FinanceArgs::parseAmount($row['amount'] ?? '');
        } catch (ValidationException) {
            return ToolOutcome::denied('المبلغ غير صالح.', auditable: false);
        }
        $date = trim((string) ($row['expense_date'] ?? ''));
        if ($date === '') {
            $date = now(config('app.business_timezone', 'Africa/Cairo'))->toDateString();
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ToolOutcome::denied('تاريخ المصروف بصيغة YYYY-MM-DD.', auditable: false);
        }
        $type = $treasury->type instanceof \BackedEnum ? $treasury->type->value : (string) $treasury->type;
        if ($type === 'pos_drawer') {
            return ToolOutcome::denied('مصروفات أدراج نقطة البيع تُسجل من مسار نقطة البيع فقط.', auditable: false);
        }
        if (! $treasury->is_active || ($treasury->branch_id !== null && (int) $treasury->branch_id !== (int) $branchId)) {
            return ToolOutcome::denied('الخزينة غير مفعّلة أو لا تتبع الفرع المحدد.', auditable: false);
        }

        return ['branch_id' => (int) $branchId, 'treasury_id' => $treasury->id, 'category' => $category,
            'amount' => $amount, 'expense_date' => $date,
            'notes' => isset($row['notes']) ? mb_substr(trim((string) $row['notes']), 0, 500) : null];
    }

    /** @return list<array<string,mixed>> */
    private function parseCsv(string $csv): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $csv) ?: []), fn ($l) => $l !== ''));
        if ($lines === []) {
            return [];
        }
        $headerMap = ['branch_id' => 'branch_id', 'branch' => 'branch_id', 'الفرع' => 'branch_id',
            'treasury_id' => 'treasury_id', 'treasury' => 'treasury_query', 'الخزينة' => 'treasury_query',
            'category' => 'category', 'الفئة' => 'category', 'amount' => 'amount', 'المبلغ' => 'amount',
            'expense_date' => 'expense_date', 'date' => 'expense_date', 'التاريخ' => 'expense_date',
            'notes' => 'notes', 'ملاحظات' => 'notes'];
        $rows = [];
        $start = 0;
        $map = null;
        $first = str_getcsv($lines[0]);
        $normalizedFirst = array_map(fn ($c) => mb_strtolower(trim((string) $c)), $first);
        $isHeader = false;
        foreach ($normalizedFirst as $cell) {
            if (isset($headerMap[$cell]) || isset($headerMap[trim((string) $cell)])) {
                $isHeader = true;
                break;
            }
        }
        if ($isHeader) {
            $start = 1;
            $map = array_map(fn ($c) => $headerMap[mb_strtolower(trim((string) $c))] ?? null, $first);
        }
        $default = ['branch_id', 'treasury_query', 'category', 'amount', 'expense_date', 'notes'];
        for ($i = $start; $i < count($lines); $i++) {
            $cells = str_getcsv($lines[$i]);
            $row = [];
            foreach ($cells as $j => $cell) {
                $key = $map[$j] ?? $default[$j] ?? null;
                if ($key !== null) {
                    $row[$key] = trim((string) $cell);
                }
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
