<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance;

use App\Models\Expense;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use App\Services\SpokenQuantity;
use App\Support\ArabicDigits;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class FinanceArgs
{
    /**
     * @param  array<string,mixed>  $args
     * @return array{treasury: Treasury}|ToolOutcome
     */
    public static function resolveTreasury(User $actor, EntityResolver $resolver, BranchAccess $branches, array $args): array|ToolOutcome
    {
        if (! empty($args['treasury_id'])) {
            $treasury = Treasury::find((int) $args['treasury_id']);
            if (! $treasury) {
                return ToolOutcome::denied('الخزينة مش موجودة.', auditable: false);
            }
            try {
                $branches->assertTreasury($actor->id, $treasury);
            } catch (AuthorizationException) {
                return ToolOutcome::denied('الخزينة خارج نطاقك.', auditable: false);
            }

            return ['treasury' => $treasury];
        }
        $query = trim((string) ($args['treasury_query'] ?? ''));
        if ($query === '') {
            return ToolOutcome::denied('حدد الخزينة بـ treasury_id أو treasury_query.', auditable: false);
        }
        $options = $resolver->treasuries($actor, $query, array_filter([
            'type' => $args['type'] ?? null,
            'active' => $args['is_active'] ?? null,
        ], fn ($v) => $v !== null));
        if (count($options) === 0) {
            return ToolOutcome::denied('مش لاقي خزينة بالوصف ده.', auditable: false);
        }
        if (count($options) > 1) {
            return ToolOutcome::denied('لقيت أكتر من خزينة. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
        }

        return self::resolveTreasury($actor, $resolver, $branches, ['treasury_id' => $options[0]['id']]);
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{transfer: TreasuryTransfer}|ToolOutcome
     */
    public static function resolveTreasuryTransfer(User $actor, EntityResolver $resolver, BranchAccess $branches, array $args): array|ToolOutcome
    {
        if (! empty($args['transfer_id'])) {
            $transfer = TreasuryTransfer::with(['fromTreasury', 'toTreasury'])->find((int) $args['transfer_id']);
            if (! $transfer) {
                return ToolOutcome::denied('التحويل مش موجود.', auditable: false);
            }
            try {
                foreach ([$transfer->fromTreasury, $transfer->toTreasury] as $treasury) {
                    $branches->assertTreasury($actor->id, $treasury);
                }
            } catch (AuthorizationException) {
                return ToolOutcome::denied('التحويل خارج نطاقك.', auditable: false);
            }

            return ['transfer' => $transfer];
        }
        $query = trim((string) ($args['transfer_query'] ?? $args['transfer_number'] ?? ''));
        if ($query === '') {
            return ToolOutcome::denied('حدد التحويل بـ transfer_id أو transfer_query.', auditable: false);
        }
        $options = $resolver->treasuryTransfers($actor, $query);
        if (count($options) === 0) {
            return ToolOutcome::denied('مش لاقي تحويل بالوصف ده.', auditable: false);
        }
        if (count($options) > 1) {
            return ToolOutcome::denied('لقيت أكتر من تحويل. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
        }

        return self::resolveTreasuryTransfer($actor, $resolver, $branches, ['transfer_id' => $options[0]['id']]);
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{expense: Expense}|ToolOutcome
     */
    public static function resolveExpense(User $actor, EntityResolver $resolver, BranchAccess $branches, array $args): array|ToolOutcome
    {
        if (! empty($args['expense_id'])) {
            $expense = Expense::with(['branch', 'treasury'])->find((int) $args['expense_id']);
            if (! $expense) {
                return ToolOutcome::denied('المصروف مش موجود.', auditable: false);
            }
            try {
                $branches->assertBranch($actor->id, $expense->branch_id);
                $branches->assertTreasury($actor->id, $expense->treasury);
            } catch (AuthorizationException) {
                return ToolOutcome::denied('المصروف خارج نطاقك.', auditable: false);
            }

            return ['expense' => $expense];
        }
        $query = trim((string) ($args['expense_query'] ?? $args['expense_number'] ?? ''));
        if ($query === '') {
            return ToolOutcome::denied('حدد المصروف بـ expense_id أو expense_query.', auditable: false);
        }
        $options = $resolver->expenses($actor, $query);
        if (count($options) === 0) {
            return ToolOutcome::denied('مش لاقي مصروف بالوصف ده.', auditable: false);
        }
        if (count($options) > 1) {
            return ToolOutcome::denied('لقيت أكتر من مصروف. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
        }

        return self::resolveExpense($actor, $resolver, $branches, ['expense_id' => $options[0]['id']]);
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public static function normalize(array $args): array
    {
        return self::normalizeDigitsInArray($args);
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public static function normalizeDigitsInArray(array $args): array
    {
        $intKeys = [
            'treasury_id', 'from_treasury_id', 'to_treasury_id', 'customer_id',
            'branch_id', 'expense_id', 'transfer_id', 'limit', 'cursor', 'days',
            'page', 'per_page',
        ];

        foreach ($args as $key => $value) {
            if (is_string($value)) {
                $converted = ArabicDigits::normalize($value);
                if (in_array($key, ['amount', 'total', 'expected_amount'], true)) {
                    $args[$key] = trim($converted);
                } elseif (in_array($key, $intKeys, true) && is_numeric($converted)) {
                    $args[$key] = (int) $converted;
                } else {
                    $args[$key] = $value;
                }
            } elseif (is_array($value)) {
                $args[$key] = self::normalizeDigitsInArray($value);
            }
        }

        return $args;
    }

    /**
     * Parses a spoken or written Arabic/English amount into a 2-decimal string.
     *
     * Understands Arabic-Indic digits ("١٥٠٠"), thousands separators ("1,500"),
     * fractional thousands ("1.5 ألف"), bare words ("ألف", "ألفين", "مليون",
     * "مليونين", "نص مليون", "ربع مليون") and the "2k" shorthand. Currency
     * words (جنيه، ج.م) are ignored.
     *
     * @throws ValidationException If the amount is not positive or exceeds 99,999,999
     */
    public static function parseAmount(mixed $v): string
    {
        $raw = is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : '');
        $s = trim(ArabicDigits::normalize($raw));
        if ($s === '') {
            throw ValidationException::withMessages(['amount' => ['صيغة المبلغ غير مفهومة.']]);
        }
        $s = str_replace('٫', '.', $s);
        $s = mb_strtolower($s);
        // Currency words carry no numeric meaning — drop them before parsing.
        $s = preg_replace('/(جنيهات|جنيها|جنيه|جم|ج\.م|egp|pounds?)\b/u', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        $n = EntityResolver::normalize($s);
        if (str_starts_with($s, '-') || preg_match('/^(سالب|ناقص|بالسالب)\b/u', $n) === 1) {
            throw ValidationException::withMessages(['amount' => ['المبلغ لازم يكون أكبر من صفر.']]);
        }
        $n = ltrim($n, '- ');
        // Thousands separators ("1,500") and digit grouping spaces ("1 500").
        $n = str_replace([',', '٬', '’', "'", '_'], '', $n);
        $n = preg_replace('/(?<=\d)\s+(?=\d)/u', '', $n) ?? $n;
        $n = trim($n);

        // Bare and half/quarter words (hamza-insensitive via normalize()).
        $words = [
            '/^(نصف|نص)\s+مليون$/' => '500000',
            '/^ربع\s+مليون$/' => '250000',
            '/^مليون\s+ونص$/' => '1500000',
            '/^(نصف|نص)\s+الف$/' => '500',
            '/^الف\s+ونص$/' => '1500',
            '/^مليونين(\s+ونص)?$/' => null,
            '/^الفين(\s+ونص)?$/' => null,
            '/^الف$/' => '1000',
            '/^مليون$/' => '1000000',
        ];
        foreach ($words as $pattern => $fixed) {
            if (preg_match($pattern.'u', $n, $m) === 1) {
                if ($fixed !== null) {
                    return self::finalizeAmount(BigDecimal::of($fixed));
                }
                $base = str_contains($pattern, 'مليونين') ? '2000000' : '2000';
                $half = str_contains($pattern, 'مليونين') ? '500000' : '500';

                return self::finalizeAmount(
                    isset($m[1]) && trim($m[1]) !== ''
                        ? BigDecimal::of($base)->plus($half)
                        : BigDecimal::of($base)
                );
            }
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*مليون$/u', $n, $m) === 1) {
            return self::finalizeAmount(BigDecimal::of($m[1])->multipliedBy('1000000'));
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(الف|k)$/u', $n, $m) === 1) {
            return self::finalizeAmount(BigDecimal::of($m[1])->multipliedBy('1000'));
        }
        // Plural thousands in words ("خمسة آلاف") via the spoken-quantity parser.
        if (str_contains($n, 'الاف')) {
            $spoken = SpokenQuantity::parse(str_replace('الاف', 'الف', $n));
            if ($spoken !== null) {
                return self::finalizeAmount(BigDecimal::of((string) $spoken));
            }
        }
        if (preg_match('/^\d+(?:\.\d+)?$/u', $n) === 1) {
            try {
                return self::finalizeAmount(BigDecimal::of($n));
            } catch (MathException) {
                // Falls through to the generic format error below.
            }
        }

        throw ValidationException::withMessages(['amount' => ['صيغة المبلغ غير مفهومة.']]);
    }

    /**
     * @throws ValidationException
     */
    private static function finalizeAmount(BigDecimal $amount): string
    {
        if ($amount->isLessThanOrEqualTo('0')) {
            throw ValidationException::withMessages(['amount' => ['المبلغ لازم يكون أكبر من صفر.']]);
        }
        if ($amount->isGreaterThan('99999999')) {
            throw ValidationException::withMessages(['amount' => ['المبلغ أكبر من الحد المسموح (99,999,999).']]);
        }

        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Parses a colloquial Arabic date range using the business timezone —
     * the same timezone guard() uses for the daily token budget.
     *
     * @return array{from: string, to: string} Y-m-d bounds, inclusive
     *
     * @throws ValidationException If the text names no known range
     */
    public static function parseDateRange(?string $text, ?string $timezone = null): array
    {
        $tz = $timezone ?? (string) config('app.business_timezone', 'Africa/Cairo');
        $now = now($tz);
        $today = $now->toDateString();

        $n = EntityResolver::normalizeForSearch(trim(ArabicDigits::normalize((string) $text)));
        if ($n === '' || $n === 'نهارده' || $n === 'يوم' || $n === 'نهارده يوم') {
            return ['from' => $today, 'to' => $today];
        }
        if (str_contains($n, 'مبارح') || preg_match('/(^|\s)مس($|\s)/u', $n) === 1) {
            $yesterday = $now->copy()->subDay()->toDateString();

            return ['from' => $yesterday, 'to' => $yesterday];
        }
        if (str_contains($n, 'سبوع')) {
            return ['from' => $now->copy()->startOfWeek()->toDateString(), 'to' => $today];
        }
        if (str_contains($n, 'شهر')) {
            if (str_contains($n, 'فات') || str_contains($n, 'ماضي') || str_contains($n, 'لي فات')) {
                $prev = $now->copy()->subMonth();

                return ['from' => $prev->copy()->startOfMonth()->toDateString(), 'to' => $prev->copy()->endOfMonth()->toDateString()];
            }

            return ['from' => $now->copy()->startOfMonth()->toDateString(), 'to' => $today];
        }
        if (preg_match('/خر\s+(\d+)\s+يام/u', $n, $m) === 1) {
            $days = max(1, (int) $m[1]);

            return ['from' => $now->copy()->subDays($days - 1)->toDateString(), 'to' => $today];
        }

        throw ValidationException::withMessages(['date_range' => ['صيغة الفترة غير مفهومة.']]);
    }
}
