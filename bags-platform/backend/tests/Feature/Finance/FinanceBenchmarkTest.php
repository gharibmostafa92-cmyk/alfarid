<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Services\Ai\DomainRouter;
use Tests\TestCase;

/**
 * Phase 6.3: finance benchmark — 60 colloquial prompts with expected domain,
 * tool and args. Domain routing is deterministic (asserted 60/60 here);
 * the tool/args column drives model-side evaluation (target ≥95%).
 *
 * Focus areas: product-wallet vs customer-wallet, money vs goods transfer,
 * no model-side arithmetic, self-approval refusal, injection in expense notes.
 */
class FinanceBenchmarkTest extends TestCase
{
    /**
     * @return list<array{prompt:string,domains:list<string>,tool:string|null,args:array<string,mixed>}>
     */
    public static function prompts(): array
    {
        $dataset = json_decode(file_get_contents(base_path('tests/Fixtures/ai-agent-finance.json')), true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn (array $case) => [
            'prompt' => $case['text'], 'domains' => $case['domains'], 'tool' => $case['tool'], 'args' => $case['args'],
        ], $dataset['cases']);
    }

    public function test_benchmark_routing_and_tool_registration(): void
    {
        $router = app(DomainRouter::class);
        $definitions = app(\App\Services\Ai\ToolRegistry::class)->definitions();
        $failures = [];

        foreach (self::prompts() as $i => $case) {
            $domains = $router->route($case['prompt']) ?? [];
            foreach ($case['domains'] as $must) {
                if (! in_array($must, $domains, true)) {
                    $failures[] = '#'.($i + 1)." «{$case['prompt']}» must route to {$must}, got [".implode(',', $domains).']';
                }
            }
            if (in_array('finance', $case['domains'], true)) {
                foreach (['inventory', 'catalog', 'pricing'] as $other) {
                    if (in_array($other, $domains, true) && ! in_array($other, $case['domains'], true)) {
                        $failures[] = '#'.($i + 1)." «{$case['prompt']}» must not route to {$other}, got [".implode(',', $domains).']';
                    }
                }
                if ($case['tool'] !== null && ! isset($definitions[$case['tool']])) {
                    $failures[] = '#'.($i + 1)." tool {$case['tool']} is not registered";
                }
            } else {
                if (in_array('finance', $domains, true)) {
                    $failures[] = '#'.($i + 1)." «{$case['prompt']}» must not route to finance, got [".implode(',', $domains).']';
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
        $this->assertCount(60, self::prompts());
    }
}
