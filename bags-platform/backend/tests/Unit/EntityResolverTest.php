<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\EntityResolver;
use Tests\TestCase;

class EntityResolverTest extends TestCase
{
    public function test_normalization_removes_arabic_diacritics_and_tatweel(): void
    {
        $this->assertSame('احمد', EntityResolver::normalize(' أَحْمَـد '));
        $this->assertSame('مدرسه', EntityResolver::normalize('مدرسة'));
    }

    public function test_search_tolerates_definite_articles_without_matching_unrelated_names(): void
    {
        $resolver = app(EntityResolver::class);
        $this->assertTrue($resolver->matches('مشرف المخزن', ['مشرف مخزن']));
        $this->assertTrue($resolver->matches('المعادي', ['فرع المعادي', 'BR-MAADI']));
        $this->assertFalse($resolver->matches('الدقي', ['فرع المعادي', 'BR-MAADI']));
    }
}
