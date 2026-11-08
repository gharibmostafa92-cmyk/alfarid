<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\DomainRouter;
use Tests\TestCase;

class TraderBenchmark150Test extends TestCase
{
    /** @return list<array{id: string, text: string, expect: list<string>|null, note: string|null}> */
    private function dataset(): array
    {
        $dataset = json_decode(
            file_get_contents(base_path('tests/Fixtures/trader-benchmark-150.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $this->assertSame(150, $dataset['count']);
        $this->assertCount(150, $dataset['cases']);

        return $dataset['cases'];
    }

    public function test_fixture_has_150_unique_wellformed_cases(): void
    {
        $cases = $this->dataset();

        $ids = array_column($cases, 'id');
        $this->assertCount(150, array_unique($ids), 'Case ids must be unique.');

        foreach ($cases as $case) {
            $this->assertMatchesRegularExpression('/^tr-\d{3}$/', $case['id']);
            $this->assertNotEmpty($case['text'], $case['id']);
            if ($case['expect'] !== null) {
                $this->assertNotEmpty($case['expect'], $case['id']);
            }
        }
    }

    public function test_all_150_sentences_route_to_expected_domains(): void
    {
        $router = app(DomainRouter::class);
        $failures = [];

        foreach ($this->dataset() as $case) {
            $actual = $router->route($case['text']);
            $expected = $case['expect'];

            if ($expected === null) {
                if ($actual !== null) {
                    $failures[] = "{$case['id']}: expected unrouted, got [".implode(',', $actual).'] :: '.$case['text'];
                }

                continue;
            }

            $sortedActual = $actual === null ? null : array_values(array_unique($actual));
            sort($sortedActual);
            $sortedExpected = array_values(array_unique($expected));
            sort($sortedExpected);

            if ($sortedActual !== $sortedExpected) {
                $failures[] = "{$case['id']}: expected [".implode(',', $sortedExpected).'], got ['
                    .($sortedActual === null ? 'NULL' : implode(',', $sortedActual)).'] :: '.$case['text'];
            }
        }

        $this->assertSame([], $failures, count($failures)." of 150 trader sentences misrouted:\n".implode("\n", $failures));
    }
}
