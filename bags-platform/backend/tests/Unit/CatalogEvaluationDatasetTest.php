<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\EvaluateAiAgent;
use App\Services\Ai\Catalog\CatalogToolProvider;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolRegistry;
use Tests\TestCase;

class CatalogEvaluationDatasetTest extends TestCase
{
    public function test_catalog_safety_cases_expect_refusal_instead_of_a_write_call(): void
    {
        $dataset = json_decode(file_get_contents(base_path('tests/Fixtures/ai-agent-egyptian.json')), true, flags: JSON_THROW_ON_ERROR);
        $cases = array_column($dataset['cases'], null, 'id');
        foreach (['neg-05', 'neg-06', 'neg-07', 'neg-08'] as $id) {
            $this->assertNull($cases[$id]['tool'], $id);
        }
    }

    public function test_evaluation_accepts_equivalent_supported_references_and_relative_category_order(): void
    {
        $command = new EvaluateAiAgent;
        $normalize = new \ReflectionMethod($command, 'normalizeCatalogEvaluationArgs');
        $compare = new \ReflectionMethod($command, 'containsArgs');
        $context = ['products' => [['id' => 301, 'code' => 'P00042']], 'categories' => [['id' => 201, 'parent_id' => null], ['id' => 202, 'parent_id' => null]]];
        $actual = $normalize->invoke($command, $context, 'get_product', ['code' => 'p٠٠٠٤٢']);
        $this->assertTrue($compare->invoke($command, $actual, ['product_id' => 301]));
        $this->assertFalse($compare->invoke($command, $actual, ['product_id' => 302]));
        $actualOrder = $normalize->invoke($command, $context, 'reorder_categories', ['items' => [['id' => 202, 'sort_order' => 10], ['id' => 201, 'sort_order' => 20]]]);
        $expectedOrder = $normalize->invoke($command, $context, 'reorder_categories', ['items' => [['id' => 202, 'sort_order' => 0], ['id' => 201, 'sort_order' => 1]]]);
        $this->assertTrue($compare->invoke($command, $actualOrder, $expectedOrder));
        $this->assertFalse($compare->invoke($command, ['ids' => [12, 11]], ['ids' => [11, 12]]));
    }

    public function test_evaluation_contract_check_rejects_unknown_keys_and_invalid_enums(): void
    {
        $command = new EvaluateAiAgent;
        $matches = new \ReflectionMethod($command, 'matchesDeclaration');
        $definitions = app(ToolRegistry::class)->definitions();
        $this->assertFalse($matches->invoke($command, ['new_parent_id' => 201], $definitions['move_category']['declaration']['parameters']));
        $this->assertFalse($matches->invoke($command, ['status' => 'active'], $definitions['set_product_status']['declaration']['parameters']));
        $this->assertTrue($matches->invoke($command, ['status' => 'published'], $definitions['set_product_status']['declaration']['parameters']));
    }

    public function test_catalog_expected_arguments_follow_the_current_tool_declarations(): void
    {
        $definitions = app(ToolRegistry::class)->definitions();
        $dataset = json_decode(file_get_contents(base_path('tests/Fixtures/ai-agent-egyptian.json')), true, flags: JSON_THROW_ON_ERROR);
        $readCases = 0;
        foreach ($dataset['cases'] as $case) {
            $definition = $definitions[$case['tool'] ?? ''] ?? null;
            if (! ($definition['provider'] ?? null) instanceof CatalogToolProvider) {
                continue;
            }
            $readCases += $definition['kind'] === ToolKind::Read ? 1 : 0;
            $properties = (array) $definition['declaration']['parameters']['properties'];
            foreach ($case['args'] as $key => $value) {
                $this->assertArrayHasKey($key, $properties, $case['id']);
                if (isset($properties[$key]['enum'])) {
                    $this->assertContains($value, $properties[$key]['enum'], $case['id']);
                }
            }
        }
        $this->assertGreaterThanOrEqual(30, $readCases, 'The catalog needs at least 30 read evaluation cases.');
    }
}
