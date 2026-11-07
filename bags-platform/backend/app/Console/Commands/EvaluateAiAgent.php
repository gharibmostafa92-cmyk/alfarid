<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StaffRole;
use App\Models\User;
use App\Services\Ai\AgentOrchestrator;
use App\Services\Ai\Catalog\CatalogToolProvider;
use App\Services\Ai\DomainRouter;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Finance\FinanceArgs;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\GeminiException;
use App\Services\Ai\Inventory\InventoryToolProvider;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\ToolRegistry;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Offline evaluation benchmark for AI tool calling accuracy against Egyptian Arabic test datasets.
 */
final class EvaluateAiAgent extends Command
{
    protected $signature = 'ai:evaluate {--finance : Evaluate the finance benchmark} {--actor= : Staff ID to evaluate permissions for} {--model=} {--case=* : Evaluate selected case IDs} {--rpm=10 : Maximum provider requests per minute during evaluation} {--check : Validate the dataset without contacting Gemini} {--toolsets : Route declarations by domain to compare with the full tool list} {--min-accuracy=}';

    private float $lastRequestAt = 0;

    protected $description = 'Evaluate Egyptian Arabic tool selection without executing tools or changing application data';

    public function handle(GeminiClient $gemini, ToolRegistry $registry, AgentOrchestrator $orchestrator): int
    {
        $finance = $this->option('finance');
        $fixture = $finance ? 'ai-agent-finance.json' : 'ai-agent-egyptian.json';
        $dataset = json_decode(file_get_contents(base_path('tests/Fixtures/'.$fixture)), true, flags: JSON_THROW_ON_ERROR);
        $definitions = $registry->definitions();
        $ids = [];
        foreach ($dataset['cases'] as $case) {
            if (empty($case['id']) || in_array($case['id'], $ids, true) || ! is_string($case['text']) || ! is_array($case['args']) || ($case['tool'] !== null && ! isset($definitions[$case['tool']]))) {
                $this->error('Invalid evaluation dataset.');

                return self::FAILURE;
            }
            $ids[] = $case['id'];
            $definition = $definitions[$case['tool'] ?? ''] ?? null;
            if ((($definition['provider'] ?? null) instanceof CatalogToolProvider || ($definition['provider'] ?? null) instanceof InventoryToolProvider)
                && ! $this->matchesDeclaration($case['args'], $definition['declaration']['parameters'])) {
                $this->error('Invalid tool arguments in evaluation case '.$case['id'].'.');

                return self::FAILURE;
            }
        }
        $requiredCases = $finance ? 60 : 220;
        if (count($ids) < $requiredCases) {
            $this->error("At least {$requiredCases} evaluation cases are required.");

            return self::FAILURE;
        }
        if ($this->option('check')) {
            $this->info(count($ids).' evaluation cases validated; no provider calls made.');

            return self::SUCCESS;
        }
        $cases = $dataset['cases'];
        if ($selected = $this->option('case')) {
            $cases = array_values(array_filter($cases, fn ($case) => in_array($case['id'], $selected, true)));
            if (count($cases) !== count(array_unique($selected))) {
                $this->error('Unknown case ID.');

                return self::FAILURE;
            }
            $ids = array_column($cases, 'id');
        }
        $minimum = (float) ($this->option('min-accuracy') ?? ($finance ? 0.95 : 0.85));
        if (! ctype_digit((string) $this->option('rpm')) || (int) $this->option('rpm') < 1) {
            $this->error('rpm must be a positive integer.');

            return self::FAILURE;
        }
        if ($minimum < 0 || $minimum > 1) {
            $this->error('min-accuracy must be between 0 and 1.');

            return self::FAILURE;
        }
        $actor = $this->option('actor') ? User::findOrFail($this->option('actor')) : User::role(StaffRole::SuperAdmin->value, 'staff')->where('is_active', true)->firstOrFail();
        $declarations = $registry->declarationsFor($actor);
        if ($finance) {
            $missing = array_diff(array_unique(array_filter(array_column($cases, 'tool'))), array_column($declarations, 'name'));
            if ($missing !== []) {
                $this->error('Evaluation actor or feature flags hide expected tools: '.implode(', ', $missing));

                return self::FAILURE;
            }
        }
        $system = $orchestrator->systemPrompt($actor)."\nبيانات محاكاة مرجعية موثوقة لاختبار اختيار الأدوات. يمكنك طلب أدوات البحث قبل اقتراح التعديل؛ لا تؤكد تنفيذ أي تعديل: ".json_encode($dataset['context'], JSON_UNESCAPED_UNICODE);
        $passed = 0;
        $pricingTotal = count(array_filter($cases, fn ($case) => str_starts_with($case['id'], 'pricing-')));
        $pricingPassed = 0;
        $errors = 0;
        foreach ($cases as $case) {
            try {
                $observed = [];
                $caseDeclarations = $this->option('toolsets')
                    ? $registry->declarationsFor($actor, app(DomainRouter::class)->route($case['text']))
                    : $declarations;
                $match = $this->evaluateCase($gemini, $registry, $caseDeclarations, $system, $dataset['context'], $case, $observed);
                $passed += $match ? 1 : 0;
                if ($match && str_starts_with($case['id'], 'pricing-')) {
                    $pricingPassed++;
                }
                $this->line($case['id'].': '.($match ? 'PASS' : 'FAIL').' ('.implode(' → ', $observed).')');
            } catch (\Throwable $exception) {
                report($exception);
                $errors++;
                $status = $exception instanceof GeminiException ? $exception->providerStatus : null;
                $this->error($case['id'].': provider request failed'.($status ? ' (HTTP '.$status.')' : '').'.');
            }
        }
        $accuracy = $passed / count($ids);
        $this->info(sprintf('Accuracy: %.1f%% (%d/%d); required %.1f%%', $accuracy * 100, $passed, count($ids), $minimum * 100));
        if ($errors) {
            $this->warn($errors.' cases could not complete because of provider errors.');
        }

        $pricingAccuracy = $pricingTotal > 0 ? $pricingPassed / $pricingTotal : null;
        if ($pricingAccuracy !== null) {
            $this->info(sprintf('Pricing accuracy: %.1f%% (%d/%d); required 90.0%%', $pricingAccuracy * 100, $pricingPassed, $pricingTotal));
        }

        return $accuracy >= $minimum && ($pricingAccuracy === null || $pricingAccuracy >= 0.9) ? self::SUCCESS : self::FAILURE;
    }

    private function evaluateCase(GeminiClient $gemini, ToolRegistry $registry, array $declarations, string $system, array $context, array $case, array &$observed): bool
    {
        $history = [['role' => 'user', 'parts' => [['text' => $case['text']]]]];
        for ($step = 0; $step < 6; $step++) {
            $interval = 60 / (int) $this->option('rpm');
            $delay = $interval - (microtime(true) - $this->lastRequestAt);
            if ($delay > 0) {
                usleep((int) ($delay * 1000000));
            }
            $this->lastRequestAt = microtime(true);
            $response = $gemini->converse($history, $declarations, $system, $this->option('model') ?: null);
            if (! $response['calls']) {
                return $case['tool'] === null;
            }
            $history[] = ['role' => 'model', 'parts' => $response['parts']];
            $responses = [];
            $matched = false;
            foreach ($response['calls'] as $call) {
                $observed[] = $call['name'];
                $args = $call['args'];
                if ($this->output->isVerbose()) {
                    $this->line(json_encode($call, JSON_UNESCAPED_UNICODE));
                }
                if ($call['name'] === 'set_branch_status' && ! isset($args['branch_id']) && isset($args['branch_query'])) {
                    $results = $this->fixtureResponse($context, ['name' => 'find_entities', 'args' => ['type' => 'branch', 'query' => $args['branch_query']]])['results'];
                    if (count($results) === 1) {
                        $args['branch_id'] = $results[0]['id'];
                    }
                }
                if (in_array($call['name'], ['list_locations', 'create_location']) && ! isset($args['branch_id']) && isset($args['branch_query'])) {
                    $results = $this->fixtureResponse($context, ['name' => 'find_entities', 'args' => ['type' => 'branch', 'query' => $args['branch_query']]])['results'];
                    if (count($results) === 1) {
                        $args['branch_id'] = $results[0]['id'];
                    }
                }
                $expected = $case['args'];
                if ($this->option('finance') && $call['name'] === $case['tool']) {
                    $args = $this->normalizeFinanceArgs($context, $args);
                    $expected = $this->normalizeFinanceArgs($context, $expected);
                }
                $correct = $call['name'] === $case['tool'] && $this->containsArgs(
                    $this->normalizeCatalogEvaluationArgs($context, $call['name'], $args),
                    $this->normalizeCatalogEvaluationArgs($context, $case['tool'] ?? '', $expected),
                );
                // Never call dispatch/execute here. An unexpected write invalidates the case.
                if ($registry->isWrite($call['name']) && ! $correct) {
                    return false;
                }
                $matched = $matched || $correct;
                $responses = array_merge($responses, ToolOutcome::data($this->fixtureResponse($context, $call))->asFunctionResponse($call)['parts']);
            }
            if ($matched) {
                return true;
            }
            $history[] = ['role' => 'user', 'parts' => $responses];
        }

        return false;
    }

    private function fixtureResponse(array $context, array $call): array
    {
        $normalize = fn ($value) => EntityResolver::normalizeForSearch((string) $value);
        if ($call['name'] === 'list_permissions' || ($call['name'] === 'find_entities' && ($call['args']['type'] ?? '') === 'permission')) {
            return ['permissions' => ['inventory.view', 'inventory.adjust', 'pos.discount', 'products.view_cost', 'inventory.cost']];
        }
        $key = match ($call['name']) {
            'list_branches' => 'branches', 'list_staff' => 'users', 'list_roles' => 'roles',
            'list_customers' => 'customers', 'list_customer_groups' => 'customer_groups',
            'list_categories' => 'categories', 'search_products' => 'products',
            'get_stock_levels' => 'levels', 'stock_matrix' => 'levels', 'explain_balance' => 'levels',
            'low_stock_list' => 'levels', 'dead_stock' => 'levels', 'inventory_valuation' => 'levels',
            'inventory_health' => 'levels', 'list_production_receipts' => 'levels',
            'list_movements' => 'movements',
            'list_transfers' => 'transfers', 'get_transfer' => 'transfers',
            'list_stocktakes' => 'stocktakes', 'get_stocktake' => 'stocktakes',
            'list_price_lists', 'get_price_list' => 'price_lists',
            'list_coupons', 'get_coupon' => 'coupons',
            'list_promotions' => 'promotions',
            'list_treasuries', 'get_treasury' => 'treasuries',
            'list_treasury_transfers', 'get_treasury_transfer', 'my_approval_queue' => 'treasury_transfers',
            'list_expenses' => 'expenses',
            'find_entities' => match ($call['args']['type'] ?? '') {
                'branch' => 'branches', 'user' => 'users', 'role' => 'roles', 'location' => 'locations', 'customer' => 'customers', 'customer_group' => 'customer_groups', 'category' => 'categories', 'product' => 'products', 'variant' => 'variants', 'transfer' => 'transfers', 'stocktake' => 'stocktakes',
                'price_list' => 'price_lists', 'coupon' => 'coupons', 'promotion' => 'promotions', 'treasury' => 'treasuries',
                'treasury_transfer' => 'treasury_transfers', 'expense' => 'expenses', default => '',
            },
            'list_locations' => 'locations',
            default => '',
        };
        if ($call['name'] === 'view_settings' || $call['name'] === 'update_settings') {
            return ['settings' => ['tax_rate' => '15', 'tax_name' => 'ضريبة القيمة المضافة', 'tax_included' => 'true', 'return_window_days' => '14'], 'executed' => $call['name'] === 'update_settings'];
        }
        if ($call['name'] === 'view_document_numbering' || $call['name'] === 'update_document_numbering') {
            $type = $call['args']['type'] ?? 'invoice';

            return ['numbering' => ['type' => $type, 'prefix' => 'INV-', 'next_number' => 1, 'padding' => 5], 'executed' => $call['name'] === 'update_document_numbering'];
        }
        if (in_array($call['name'], ['create_location', 'set_location_status', 'delete_location'])) {
            return ['executed' => false, 'message' => 'يحتاج تأكيد'];
        }
        if (! $key) {
            return ['context' => $context, 'executed' => false];
        }
        $query = $normalize($call['args']['query'] ?? '');
        $items = $context[$key] ?? [];
        if ($key === 'variants') {
            $items = $this->inventoryVariants($context);
        }
        // For locations, filter by branch_id if provided
        if ($key === 'locations' && isset($call['args']['branch_id'])) {
            $items = array_values(array_filter($items, fn ($item) => ($item['branch_id'] ?? null) === $call['args']['branch_id']));
        }
        // Convert Arabic-Indic digits to standard ASCII before matching phone numbers, mirroring Customer::normalizePhone.
        $digits = preg_replace('/\D+/', '', EntityResolver::normalizeDigits((string) ($call['args']['query'] ?? '')));
        $items = array_values(array_filter($items, fn ($item) => $query === ''
            || str_contains($normalize($item['name'].' '.($item['code'] ?? '').' '.($item['sku'] ?? '').' '.($item['barcode'] ?? '').' '.($item['company_name'] ?? '')), $query)
            || ($key === 'variants' && $this->matchesInventoryDescription($item, $query))
            || ($digits !== '' && isset($item['phone']) && str_starts_with((string) $item['phone'], $digits))));
        // Customer list filters (e.g. status) are not simulated in test fixtures; assertion evaluates tool name and args.
        $items = array_map(fn ($item) => ['id' => $item['id'], 'label' => isset($item['company_name']) ? $item['name'].' — '.$item['company_name'] : $item['name'], 'meta' => $item['code'] ?? ''], $items);

        return [$call['name'] === 'find_entities' ? 'results' : $key => $items];
    }

    private function normalizeFinanceArgs(array $context, array $args): array
    {
        if (isset($args['amount'])) {
            try {
                $args['amount'] = FinanceArgs::parseAmount($args['amount']);
            } catch (ValidationException) {
                // An unparseable amount cannot match a valid expected value.
            }
        }
        if (is_string($args['category'] ?? null)) {
            $category = $args['category'];
            $args['category'] = EntityResolver::normalizeForSearch(config('ai_finance_expense_categories')[$category] ?? $category);
        }
        if (isset($args['treasury_id'])) {
            $args['treasury_id'] = (int) $args['treasury_id'];
        } elseif (is_string($args['treasury_query'] ?? null)) {
            $query = EntityResolver::normalizeForSearch($args['treasury_query']);
            $matches = array_values(array_filter($context['treasuries'] ?? [], fn ($row) => $query !== ''
                && str_contains(EntityResolver::normalizeForSearch($row['name']), $query)));
            if (count($matches) === 1) {
                unset($args['treasury_query']);
                $args['treasury_id'] = $matches[0]['id'];
            }
        }

        return $args;
    }

    private function containsArgs(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }
            if (is_array($value)) {
                if (array_is_list($value)) {
                    if (! is_array($actual[$key])) {
                        return false;
                    }
                    $left = $actual[$key];
                    $right = $value;
                    if ($key !== 'ids') {
                        sort($left);
                        sort($right);
                    }
                    if ($left !== $right) {
                        return false;
                    }

                    continue;
                }
                if (! is_array($actual[$key]) || ! $this->containsArgs($actual[$key], $value)) {
                    return false;
                }
            } elseif ($actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /** Expected arguments are partial assertions, but their keys and enums must exist in the tool contract. */
    private function matchesDeclaration(array $args, array $schema): bool
    {
        if (($schema['type'] ?? null) === 'ARRAY') {
            foreach ($args as $value) {
                if (is_array($value) && ! $this->matchesDeclaration($value, $schema['items'] ?? [])) {
                    return false;
                }
            }

            return true;
        }
        $properties = (array) ($schema['properties'] ?? []);
        foreach ($args as $key => $value) {
            if (! array_key_exists($key, $properties)) {
                return false;
            }
            $property = $properties[$key];
            if (isset($property['enum']) && ! in_array($value, $property['enum'], true)) {
                return false;
            }
            if (is_array($value) && ! $this->matchesDeclaration($value, $property)) {
                return false;
            }
        }

        return true;
    }

    /** Compare equivalent inputs the catalog tools support, without accepting unknown aliases. */
    private function normalizeCatalogEvaluationArgs(array $context, string $tool, array $args): array
    {
        foreach (['transfer' => 'transfers', 'stocktake' => 'stocktakes'] as $entity => $collection) {
            $numberKey = $entity.'_number';
            if (isset($args[$numberKey])) {
                foreach ($context[$collection] ?? [] as $item) {
                    if (mb_strtolower((string) ($item['code'] ?? '')) === mb_strtolower((string) $args[$numberKey])) {
                        $args[$entity.'_id'] = $item['id'];
                        unset($args[$numberKey]);
                        break;
                    }
                }
            }
        }
        if (in_array($tool, ['get_stock_levels', 'list_movements', 'explain_balance', 'adjust_stock', 'what_if_production_cost', 'what_if_transfer_coverage'], true)) {
            if (! isset($args['variant_id'])) {
                $query = (string) ($args['sku'] ?? $args['barcode'] ?? $args['query'] ?? '');
                $matches = array_values(array_filter($this->inventoryVariants($context), fn ($item) => $this->matchesInventoryDescription($item, $query)));
                if (count($matches) === 1) {
                    $args['variant_id'] = $matches[0]['id'];
                    unset($args['sku'], $args['barcode'], $args['query']);
                }
            }
        }
        if (in_array($tool, ['get_product', 'check_publishability'], true) && ! isset($args['product_id']) && isset($args['code'])) {
            $code = mb_strtolower(EntityResolver::normalizeDigits(trim((string) $args['code'])));
            foreach ($context['products'] ?? [] as $product) {
                if (mb_strtolower($product['code']) === $code) {
                    $args['product_id'] = $product['id'];
                    unset($args['code']);
                    break;
                }
            }
        }
        if ($tool === 'reorder_categories' && isset($args['items']) && is_array($args['items'])) {
            $parents = array_column($context['categories'] ?? [], 'parent_id', 'id');
            usort($args['items'], fn ($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
            $ranks = [];
            foreach ($args['items'] as &$item) {
                if (! array_key_exists('parent_id', $item)) {
                    $item['parent_id'] = $parents[$item['id']] ?? null;
                }
                $parent = $item['parent_id'] ?? 'root';
                $item['sort_order'] = $ranks[$parent] ?? 0;
                $ranks[$parent] = $item['sort_order'] + 1;
                ksort($item);
            }
            unset($item);
        }

        return $args;
    }

    private function inventoryVariants(array $context): array
    {
        $items = [];
        foreach (array_merge($context['variants'] ?? [], $context['levels'] ?? []) as $item) {
            $code = $item['sku'] ?? $item['code'] ?? null;
            if ($code !== null && ! isset($items[$code])) {
                $items[$code] = $item + ['sku' => $code];
            }
        }

        return array_values($items);
    }

    private function matchesInventoryDescription(array $item, string $query): bool
    {
        $normalize = function (string $text): array {
            $words = preg_split('/\s+/u', EntityResolver::normalizeForSearch($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_map(fn ($word) => preg_replace('/^ال/u', '', $word), $words);
        };
        $needle = $normalize($query);
        $haystack = $normalize(implode(' ', array_filter([$item['name'] ?? '', $item['sku'] ?? '', $item['barcode'] ?? '', $item['code'] ?? ''])));

        return $needle !== [] && array_diff($needle, $haystack) === [];
    }
}
