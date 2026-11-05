<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\Finance\FinanceArgs;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinanceArgsTest extends TestCase
{
    public static function validAmounts(): array
    {
        return [
            'plain integer' => ['1500', '1500.00'],
            'int input' => [1500, '1500.00'],
            'arabic-indic digits' => ['١٥٠٠', '1500.00'],
            'persian digits' => ['۱۲۵۰', '1250.00'],
            'thousands separator' => ['1,500', '1500.00'],
            'fractional thousands word' => ['1.5 ألف', '1500.00'],
            'bare thousand word' => ['ألف', '1000.00'],
            'dual thousand word' => ['ألفين', '2000.00'],
            'dual thousand without hamza' => ['الفين', '2000.00'],
            'k shorthand' => ['2k', '2000.00'],
            'uppercase K shorthand' => ['2K', '2000.00'],
            'half million words' => ['نص مليون', '500000.00'],
            'quarter million words' => ['ربع مليون', '250000.00'],
            'million multiplier' => ['3 مليون', '3000000.00'],
            'dual million word' => ['مليونين', '2000000.00'],
            'currency word ignored' => ['2500 جنيه', '2500.00'],
            'decimal amount' => ['1500.5', '1500.50'],
            'thousand and a half' => ['ألفين ونص', '2500.00'],
        ];
    }

    #[DataProvider('validAmounts')]
    public function test_parse_amount_understands_spoken_and_written_forms(mixed $input, string $expected): void
    {
        $this->assertSame($expected, FinanceArgs::parseAmount($input));
    }

    public static function invalidAmounts(): array
    {
        return [
            'negative with minus' => ['-500'],
            'negative arabic word' => ['سالب 500'],
            'zero' => ['0'],
            'zero decimals' => ['0.00'],
            'above maximum' => ['100000000'],
            'gibberish' => ['كلام مش مبلغ'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_parse_amount_rejects_non_positive_and_out_of_range(mixed $input): void
    {
        $this->expectException(ValidationException::class);
        FinanceArgs::parseAmount($input);
    }

    public function test_parse_date_range_understands_colloquial_ranges(): void
    {
        $tz = (string) config('app.business_timezone', 'Africa/Cairo');
        $now = now($tz);
        $today = $now->toDateString();

        $this->assertSame(['from' => $today, 'to' => $today], FinanceArgs::parseDateRange('النهارده'));
        $this->assertSame(['from' => $today, 'to' => $today], FinanceArgs::parseDateRange(null));

        $yesterday = $now->copy()->subDay()->toDateString();
        $this->assertSame(['from' => $yesterday, 'to' => $yesterday], FinanceArgs::parseDateRange('امبارح'));

        $this->assertSame(
            ['from' => $now->copy()->startOfWeek()->toDateString(), 'to' => $today],
            FinanceArgs::parseDateRange('الأسبوع ده')
        );
        $this->assertSame(
            ['from' => $now->copy()->startOfMonth()->toDateString(), 'to' => $today],
            FinanceArgs::parseDateRange('من أول الشهر')
        );

        $prev = $now->copy()->subMonth();
        $this->assertSame(
            ['from' => $prev->copy()->startOfMonth()->toDateString(), 'to' => $prev->copy()->endOfMonth()->toDateString()],
            FinanceArgs::parseDateRange('الشهر اللي فات')
        );
    }

    public function test_parse_date_range_rejects_unknown_text(): void
    {
        $this->expectException(ValidationException::class);
        FinanceArgs::parseDateRange('بعد بكرة الساعة خمسة');
    }

    public function test_digits_are_normalized_in_finance_args(): void
    {
        $this->assertSame(
            ['treasury_id' => 7, 'amount' => '1500.5', 'notes' => 'فرع ٧'],
            FinanceArgs::normalizeDigitsInArray(['treasury_id' => '٧', 'amount' => '١٥٠٠.٥', 'notes' => 'فرع ٧'])
        );
    }
}
