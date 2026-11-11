<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shared\SpokenQuantity;
use PHPUnit\Framework\TestCase;

/** Spoken hundreds must multiply a following thousand instead of adding to it. */
class SpokenQuantityTest extends TestCase
{
    public function test_hundred_before_thousand_multiplies(): void
    {
        $this->assertSame(100000.0, SpokenQuantity::parse('مية ألف'));
    }

    public function test_compound_hundred_before_thousand_multiplies(): void
    {
        $this->assertSame(150000.0, SpokenQuantity::parse('مية وخمسين ألف'));
    }

    public function test_hundred_alone_and_hundred_plus_tens_still_add(): void
    {
        $this->assertSame(100.0, SpokenQuantity::parse('ميه'));
        $this->assertSame(150.0, SpokenQuantity::parse('ميه وخمسين'));
        $this->assertSame(3000.0, SpokenQuantity::parse('تلاته الف'));
        $this->assertSame(1000.0, SpokenQuantity::parse('الف'));
        $this->assertSame(1100.0, SpokenQuantity::parse('الف وميه'));
    }

    public function test_text_path_shares_the_numeric_path_upper_bound(): void
    {
        $huge = implode(' ', array_fill(0, 11, 'تسعه وتسعين الف'));
        $this->assertNull(SpokenQuantity::parse($huge));
        $this->assertSame(99000.0, SpokenQuantity::parse('تسعه وتسعين الف'));
    }
}
