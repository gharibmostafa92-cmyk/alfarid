<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StaffRole;
use App\Models\User;
use App\Services\Ai\AgentOrchestrator;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\GeminiException;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\ToolRegistry;
use Illuminate\Console\Command;

final class EvaluateAiAgent extends Command
{
    protected $signature = 'ai:evaluate {--actor= : Staff ID to evaluate permissions for} {--model=} {--case=* : Evaluate selected case IDs} {--rpm=10 : Maximum provider requests per minute during evaluation} {--check : Validate the dataset without contacting Gemini} {--min-accuracy=0.85}';

    private float $lastRequestAt = 0;

    protected $description = 'Evaluate Egyptian Arabic tool selection without executing tools or changing application data';

    public function handle(GeminiClient $gemini, ToolRegistry $registry, AgentOrchestrator $orchestrator): int
    {
        $dataset = json_decode(file_get_contents(base_path('tests/Fixtures/ai-agent-egyptian.json')), true, flags: JSON_THROW_ON_ERROR);
        $definitions = $registry->definitions();
        $ids = [];
        foreach ($dataset['cases'] as $case) {
            if (empty($case['id']) || in_array($case['id'], $ids, true) || ! is_string($case['text']) || ! is_array($case['args']) || ($case['tool'] !== null && ! isset($definitions[$case['tool']]))) {
                $this->error('Invalid evaluation dataset.');

                return self::FAILURE;
            }
            $ids[] = $case['id'];
        }
        if (count($ids) < 60) {
            $this->error('At least 60 evaluation cases are required.');

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
        $minimum = (float) $this->option('min-accuracy');
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
        $system = $orchestrator->systemPrompt($actor)."\nبيانات محاكاة مرجعية موثوقة لاختبار اختيار الأدوات. يمكنك طلب أدوات البحث قبل اقتراح التعديل؛ لا تؤكد تنفيذ أي تعديل: ".json_encode($dataset['context'], JSON_UNESCAPED_UNICODE);
        $passed = 0;
        $errors = 0;
        foreach ($cases as $case) {
            try {
                $observed = [];
                $match = $this->evaluateCase($gemini, $registry, $declarations, $system, $dataset['context'], $case, $observed);
                $passed += $match ? 1 : 0;
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

        return $accuracy >= $minimum ? self::SUCCESS : self::FAILURE;
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
                if ($call['name'] === 'set_branch_status' && ! isset($args['branch_id']) && isset($args['branch_query'])) {
                    $results = $this->fixtureResponse($context, ['name' => 'find_entities', 'args' => ['type' => 'branch', 'query' => $args['branch_query']]])['results'];
                    if (count($results) === 1) {
                        $args['branch_id'] = $results[0]['id'];
                    }
                }
                $correct = $call['name'] === $case['tool'] && $this->containsArgs($args, $case['args']);
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
            'find_entities' => match ($call['args']['type'] ?? '') {
                'branch' => 'branches', 'user' => 'users', 'role' => 'roles', default => ''
            },
            default => '',
        };
        if (! $key) {
            return ['context' => $context, 'executed' => false];
        }
        $query = $normalize($call['args']['query'] ?? '');
        $items = array_values(array_filter($context[$key], fn ($item) => $query === '' || str_contains($normalize($item['name'].' '.($item['code'] ?? '')), $query)));
        $items = array_map(fn ($item) => ['id' => $item['id'], 'label' => $item['name'], 'meta' => $item['code'] ?? ''], $items);

        return [$call['name'] === 'find_entities' ? 'results' : $key => $items];
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
                    sort($left);
                    sort($right);
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
}
