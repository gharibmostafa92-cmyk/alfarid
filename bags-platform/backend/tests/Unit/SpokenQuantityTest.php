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

    public function test_dozen_multiplies_a_preceding_count(): void
    {
        // M2: "تلاته دسته" is 3×12=36, not 3+12=15.
        $this->assertSame(36.0, SpokenQuantity::parse('تلاته دسته'));
        $this->assertSame(24.0, SpokenQuantity::parse('اتنين دسته'));
        $this->assertSame(12.0, SpokenQuantity::parse('دسته'));
    }

    public function test_repeated_ones_or_tens_are_rejected_as_stutter(): void
    {
        // M2: "خمسه خمسه" is a repeated number (speech-recognition stutter),
        // not an addition — it must not silently become 10.
        $this->assertNull(SpokenQuantity::parse('خمسه خمسه'));
        $this->assertSame(25.0, SpokenQuantity::parse('خمسه وعشرين'));
    }

    public function test_twelve_as_a_plain_number_still_adds(): void
    {
        $this->assertSame(112.0, SpokenQuantity::parse('ميه واتناشر'));
    }

    public function test_text_path_shares_the_numeric_path_upper_bound(): void
    {
        $huge = implode(' ', array_fill(0, 11, 'تسعه وتسعين الف'));
        $this->assertNull(SpokenQuantity::parse($huge));
        $this->assertSame(99000.0, SpokenQuantity::parse('تسعه وتسعين الف'));
    }
}
