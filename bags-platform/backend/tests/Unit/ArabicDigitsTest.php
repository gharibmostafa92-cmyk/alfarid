<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shared\SpokenQuantity;
use App\Support\ArabicDigits;
use PHPUnit\Framework\TestCase;

class ArabicDigitsTest extends TestCase
{
    public function test_transliterates_eastern_arabic_and_persian_numerals(): void
    {
        $this->assertSame('0123456789', ArabicDigits::normalize('٠١٢٣٤٥٦٧٨٩'));
        $this->assertSame('0123456789', ArabicDigits::normalize('۰۱۲۳۴۵۶۷۸۹'));
        $this->assertSame('order-12345-rev2', ArabicDigits::normalize('order-١٢٣٤٥-rev۲'));
        $this->assertSame('no digits here', ArabicDigits::normalize('no digits here'));
        $this->assertSame('', ArabicDigits::normalize(''));
    }

    public function test_strip_quantity_tokens_handles_arabic_and_latin_digits(): void
    {
        $this->assertSame('حقيبة يد جلد', SpokenQuantity::stripQuantityTokens('٥ قطع حقيبة يد جلد'));
        $this->assertSame('شنطة سفر سوداء', SpokenQuantity::stripQuantityTokens('شنطة سفر سوداء دسته'));
        $this->assertSame('حزام بني', SpokenQuantity::stripQuantityTokens('حزام بني 10 حبات'));
    }
}
