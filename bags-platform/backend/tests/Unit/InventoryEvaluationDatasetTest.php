<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\EvaluateAiAgent;
use PHPUnit\Framework\TestCase;

class InventoryEvaluationDatasetTest extends TestCase
{
    public function test_what_if_cases_distinguish_missing_variants_from_explicit_variants(): void
    {
        $dataset = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/ai-agent-egyptian.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = array_column($dataset['cases'], null, 'id');
        foreach ([88, 112, 113, 114, 115] as $number) {
            $this->assertNull($cases['egy-inv-'.$number]['tool']);
        }
        $this->assertSame('what_if_production_cost', $cases['egy-inv-116']['tool']);
        $this->assertSame(401, $cases['egy-inv-116']['args']['variant_id']);
        $this->assertSame('what_if_transfer_coverage', $cases['egy-inv-117']['tool']);
        $this->assertSame(401, $cases['egy-inv-117']['args']['variant_id']);
    }

    public function test_inventory_queries_and_document_numbers_are_compared_by_unique_entity(): void
    {
        $command = new EvaluateAiAgent;
        $context = ['levels' => [['id' => 901, 'name' => 'شنطة كروس بني', 'code' => 'P00042-BRN']],
            'transfers' => [['id' => 701, 'code' => 'TRF-00041']]];
        $normalize = new \ReflectionMethod($command, 'normalizeCatalogEvaluationArgs');
        $expected = $normalize->invoke($command, $context, 'get_stock_levels', ['query' => 'الشنطة الكروس البني']);
        $actual = $normalize->invoke($command, $context, 'get_stock_levels', ['variant_id' => 901]);
        $this->assertSame($expected, $actual);
        $this->assertSame(['transfer_id' => 701], $normalize->invoke($command, $context, 'approve_transfer', ['transfer_number' => 'trf-00041']));
        $this->assertNotSame($actual, $normalize->invoke($command, $context, 'get_stock_levels', ['variant_id' => 902]));
        $this->assertSame(['query' => 'غير معروف'], $normalize->invoke($command, $context, 'get_stock_levels', ['query' => 'غير معروف']));
        $this->assertSame(['quantity' => 3, 'variant_id' => 901], $normalize->invoke($command, $context, 'adjust_stock', ['query' => 'شنطة كروس بني', 'quantity' => 3]));
    }

    public function test_ambiguous_variants_are_not_collapsed_to_a_guessed_id(): void
    {
        $command = new EvaluateAiAgent;
        $context = ['levels' => [['id' => 901, 'name' => 'شنطة بني', 'code' => 'A'], ['id' => 902, 'name' => 'شنطة بني', 'code' => 'B']]];
        $normalize = new \ReflectionMethod($command, 'normalizeCatalogEvaluationArgs');
        $this->assertSame(['query' => 'شنطة بني'], $normalize->invoke($command, $context, 'get_stock_levels', ['query' => 'شنطة بني']));
    }
}
