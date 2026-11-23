<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\WholesalePaymentNotice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Reconciles parsed bank statement credit transactions against pending wholesale payment notices.
 */
final class BankStatementMatcher
{
    private const MAX_STATEMENT_ROWS = 500;

    private const MAX_CANDIDATE_NOTICES = 2000;

    private const DATE_TOLERANCE_DAYS = 1;

    private const MIN_CUSTOMER_SUGGESTION_LENGTH = 4;

    private const ARABIC_INDIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ASCII_DIGITS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function cleanAmount(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '0.00';
        }

        $normalized = self::normalizeDigits(trim((string) $amount));
        if (self::isDebitEntry($normalized)) {
            return '0.00';
        }

        $numeric = self::normalizeDecimalSeparators(self::stripCurrencyMarkers($normalized));

        return self::formatPositiveDecimal($numeric);
    }

    public static function cleanReference(?string $reference): string
    {
        if ($reference === null) {
            return '';
        }

        $ref = self::normalizeDigits(mb_strtolower(trim($reference)));
        $ref = str_replace(['أ', 'إ', 'آ', 'ٱ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ا', 'ه', 'ي'], $ref);

        return preg_replace('/[\s.,_()\/#\\-]+/u', '', $ref) ?? '';
    }

    public static function bankIdentity(?string $bank): string
    {
        $compact = self::cleanReference($bank);
        $compact = preg_replace('/[^a-z0-9\p{Arabic}]+/u', '', $compact) ?? $compact;

        return match ($compact) {
            'cib', 'commercialinternationalbank', 'commercialinternationalbankegypt', 'البنكالتجاريالدولي', 'البنكالتجارىالدولى' => 'cib',
            'nbe', 'nationalbankofegypt', 'البنكالأهليالمصري', 'البنكالاهليالمصري' => 'nbe',
            'banquemisr', 'banquedumisr', 'بنكمصر' => 'banque_misr',
            'qnb', 'qnbalahli', 'بنكقطرالوطني', 'بنكقطرالوطنيالاهلي' => 'qnb',
            'aaib', 'arabafricaninternationalbank', 'البنكالعربيالافريقي', 'البنكالعربيالافريقيالدولي' => 'aaib',
            'hsbc', 'hsbcbankegypt' => 'hsbc',
            'bdc', 'banqueducaire', 'بنكالقاهرة' => 'bdc',
            'alexbank', 'bankofalexandria', 'بنكالاسكندريه' => 'alexbank',
            default => $compact,
        };
    }

    /**
     * Classifies statement credit transactions into exact, probable, or unmatched buckets against pending payment notices.
     *
     * @param  list<array{amount: float|string, reference?: string, date?: string, description?: string}>  $rows  Statement rows.
     * @return array{exact: list<array<string, mixed>>, probable: list<array<string, mixed>>, unmatched: list<array<string, mixed>>} Categorized reconciliations.
     */
    public function match(array $rows): array
    {
        if (count($rows) > self::MAX_STATEMENT_ROWS) {
            throw new BusinessException('كشف البنك يتجاوز 500 حركة؛ قسّمه إلى ملفات أصغر.', [], 422);
        }

        $noticesByAmount = $this->loadCandidateNoticesByAmount($rows);
        $parsedRows = $this->parseStatementRows($rows);

        $claimedNoticeIds = [];
        $rowResults = $this->classifyExactMatches($parsedRows, $noticesByAmount, $claimedNoticeIds);
        $rowResults = $this->classifyProbableMatches($parsedRows, $noticesByAmount, $rowResults, $claimedNoticeIds);

        $buckets = $this->partitionMatchResults($parsedRows, $rowResults);
        $buckets['unmatched'] = $this->attachCustomerSuggestions($buckets['unmatched'], $rows);

        return $buckets;
    }

    private static function normalizeDigits(string $value): string
    {
        return str_replace(self::ARABIC_INDIC_DIGITS, self::ASCII_DIGITS, $value);
    }

    private static function isDebitEntry(string $value): bool
    {
        if (preg_match('/\b(dr|debit|مدين)\b/iu', $value) === 1) {
            return true;
        }

        return preg_match('/-\s*\d/u', $value) === 1
            || str_ends_with($value, '-')
            || (str_starts_with($value, '(') && str_ends_with($value, ')'));
    }

    private static function stripCurrencyMarkers(string $value): string
    {
        $cleaned = preg_replace('/\b(egp|le|l\.e\.?)\b/iu', '', $value) ?? $value;
        $cleaned = str_replace(['ج.م.', 'ج.م', 'ج م'], '', $cleaned);

        return ltrim($cleaned, " \t.");
    }

    private static function normalizeDecimalSeparators(string $value): string
    {
        // Normalize European decimal commas (e.g. "1.500,50" -> "1500.50" or "1500,50" -> "1500.50") vs US thousands commas ("1,500.00").
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $afterComma = preg_replace('/\D/', '', substr($value, $lastComma + 1)) ?? '';
            if (strlen($afterComma) <= 2) {
                $value = str_replace('.', '', substr($value, 0, $lastComma)).'.'.$afterComma;
            }
        } elseif ($lastComma === false && preg_match('/^\s*\d{1,3}(?:\.\d{3})+\s*$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }

        return preg_replace('/[^\d.]/', '', $value) ?? '';
    }

    private static function formatPositiveDecimal(string $numeric): string
    {
        if ($numeric === '' || $numeric === '.' || substr_count($numeric, '.') > 1) {
            return '0.00';
        }

        try {
            $decimal = BigDecimal::of($numeric)->toScale(2, RoundingMode::HalfUp);

            return $decimal->isLessThanOrEqualTo('0') ? '0.00' : (string) $decimal;
        } catch (\Throwable) {
            return '0.00';
        }
    }

    private static function isPositiveAmount(string $amount): bool
    {
        return BigDecimal::of($amount)->isGreaterThan('0');
    }

    /**
     * @param  list<array{amount?: float|string}>  $rows
     * @return Collection<string, Collection<int, array{model: WholesalePaymentNotice, amount: string, reference: string, date: ?Carbon}>>
     */
    private function loadCandidateNoticesByAmount(array $rows): Collection
    {
        $amounts = array_values(array_unique(array_filter(
            array_map(static fn (array $row): string => self::cleanAmount($row['amount'] ?? 0), $rows),
            self::isPositiveAmount(...),
        )));

        $notices = WholesalePaymentNotice::query()
            ->with('customer:id,name,company_name')
            ->where('status', WholesalePaymentNoticeStatus::PendingReview)
            ->whereIn('amount', $amounts)
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATE_NOTICES + 1)
            ->get();

        if ($notices->count() > self::MAX_CANDIDATE_NOTICES) {
            throw new BusinessException('عدد الإشعارات المرشحة كبير جدًا؛ قسّم كشف البنك إلى ملفات أصغر.', [], 422);
        }

        return $notices->map(static fn (WholesalePaymentNotice $notice): array => [
            'model' => $notice,
            'amount' => self::cleanAmount($notice->amount),
            'reference' => self::cleanReference($notice->reference_number),
            'date' => $notice->transfer_date?->copy()->startOfDay(),
        ])->groupBy('amount');
    }

    /**
     * @param  list<array{amount?: float|string, reference?: string, date?: string, description?: string}>  $rows
     * @return array<int, array{index: int, amount: string, reference: string, raw_reference: string, date: ?Carbon}>
     */
    private function parseStatementRows(array $rows): array
    {
        $parsedRows = [];
        foreach ($rows as $index => $row) {
            $parsedRows[$index] = [
                'index' => $index,
                'amount' => self::cleanAmount($row['amount'] ?? 0),
                'reference' => self::cleanReference((string) ($row['reference'] ?? '')),
                'raw_reference' => (string) ($row['reference'] ?? ''),
                'date' => $this->parseStatementDate($row['date'] ?? null),
            ];
        }

        return $parsedRows;
    }

    private function parseStatementDate(mixed $rawDate): ?Carbon
    {
        if ($rawDate === null) {
            return null;
        }

        try {
            return Carbon::parse((string) $rawDate)->startOfDay();
        } catch (\Throwable) {
            // A garbage row date degrades to reference/amount matching
            // instead of aborting the whole reconciliation.
            return null;
        }
    }

    /**
     * Pass 1: classify all exact matches first using amount-indexed buckets.
     *
     * @param  array<int, array{index: int, amount: string, reference: string, raw_reference: string, date: ?Carbon}>  $parsedRows
     * @param  Collection<string, Collection<int, array{model: WholesalePaymentNotice, amount: string, reference: string, date: ?Carbon}>>  $noticesByAmount
     * @param  array<int, bool>  $claimedNoticeIds
     * @return array<int, array{kind: string, model: ?WholesalePaymentNotice}>
     */
    private function classifyExactMatches(array $parsedRows, Collection $noticesByAmount, array &$claimedNoticeIds): array
    {
        $results = [];

        foreach ($parsedRows as $index => $parsed) {
            if ($parsed['reference'] === '' || ! self::isPositiveAmount($parsed['amount'])) {
                continue;
            }

            $hit = $this->findExactNoticeMatch($parsed, $noticesByAmount->get($parsed['amount'], collect()), $claimedNoticeIds);
            if ($hit !== null) {
                $results[$index] = ['kind' => 'exact', 'model' => $hit];
                $claimedNoticeIds[$hit->id] = true;
            }
        }

        return $results;
    }

    /**
     * @param  array{reference: string, date: ?Carbon}  $parsed
     * @param  Collection<int, array{model: WholesalePaymentNotice, reference: string, date: ?Carbon}>  $candidates
     * @param  array<int, bool>  $claimedNoticeIds
     */
    private function findExactNoticeMatch(array $parsed, Collection $candidates, array $claimedNoticeIds): ?WholesalePaymentNotice
    {
        foreach ($candidates as $candidate) {
            if (isset($claimedNoticeIds[$candidate['model']->id])) {
                continue;
            }

            if ($candidate['reference'] === $parsed['reference'] && $this->datesAreClose($parsed['date'], $candidate['date'])) {
                return $candidate['model'];
            }
        }

        return null;
    }

    /**
     * Pass 2: match remaining rows as probable or unmatched.
     *
     * @param  array<int, array{index: int, amount: string, reference: string, raw_reference: string, date: ?Carbon}>  $parsedRows
     * @param  Collection<string, Collection<int, array{model: WholesalePaymentNotice, amount: string, reference: string, date: ?Carbon}>>  $noticesByAmount
     * @param  array<int, array{kind: string, model: ?WholesalePaymentNotice}>  $rowResults
     * @param  array<int, bool>  $claimedNoticeIds
     * @return array<int, array{kind: string, model: ?WholesalePaymentNotice}>
     */
    private function classifyProbableMatches(
        array $parsedRows,
        Collection $noticesByAmount,
        array $rowResults,
        array &$claimedNoticeIds,
    ): array {
        foreach ($parsedRows as $index => $parsed) {
            if (isset($rowResults[$index])) {
                continue;
            }

            if (! self::isPositiveAmount($parsed['amount'])) {
                $rowResults[$index] = ['kind' => 'unmatched', 'model' => null];

                continue;
            }

            $hit = $this->findProbableNoticeMatch($parsed, $noticesByAmount->get($parsed['amount'], collect()), $claimedNoticeIds);
            if ($hit !== null) {
                $claimedNoticeIds[$hit->id] = true;
                $rowResults[$index] = ['kind' => 'probable', 'model' => $hit];
            } else {
                $rowResults[$index] = ['kind' => 'unmatched', 'model' => null];
            }
        }

        return $rowResults;
    }

    /**
     * @param  array{reference: string, date: ?Carbon}  $parsed
     * @param  Collection<int, array{model: WholesalePaymentNotice, reference: string, date: ?Carbon}>  $candidates
     * @param  array<int, bool>  $claimedNoticeIds
     */
    private function findProbableNoticeMatch(array $parsed, Collection $candidates, array $claimedNoticeIds): ?WholesalePaymentNotice
    {
        foreach ($candidates as $candidate) {
            if (isset($claimedNoticeIds[$candidate['model']->id])) {
                continue;
            }

            $bothHaveRef = $parsed['reference'] !== '' && $candidate['reference'] !== '';
            $sameReference = $bothHaveRef && ($candidate['reference'] === $parsed['reference']);
            $conflictingReferences = $bothHaveRef && ! $sameReference;

            if ($sameReference || (! $conflictingReferences && $this->datesAreClose($parsed['date'], $candidate['date']))) {
                return $candidate['model'];
            }
        }

        return null;
    }

    private function datesAreClose(?Carbon $left, ?Carbon $right): bool
    {
        return $left !== null
            && $right !== null
            && abs($left->diffInDays($right, true)) <= self::DATE_TOLERANCE_DAYS;
    }

    /**
     * @param  array<int, array{index: int, amount: string, reference: string, raw_reference: string, date: ?Carbon}>  $parsedRows
     * @param  array<int, array{kind: string, model: ?WholesalePaymentNotice}>  $rowResults
     * @return array{exact: list<array<string, mixed>>, probable: list<array<string, mixed>>, unmatched: list<array<string, mixed>>}
     */
    private function partitionMatchResults(array $parsedRows, array $rowResults): array
    {
        $exact = [];
        $probable = [];
        $unmatched = [];

        foreach ($parsedRows as $index => $parsed) {
            $result = $rowResults[$index];
            $hit = $result['model'];
            $entry = [
                'row' => $index,
                'amount' => $parsed['amount'],
                'reference' => $parsed['raw_reference'],
                'notice_id' => $hit?->id,
                'notice_number' => $hit?->notice_number,
            ];

            if ($result['kind'] === 'exact') {
                $exact[] = $entry;
            } elseif ($result['kind'] === 'probable') {
                $probable[] = $entry;
            } else {
                $entry['suggestion'] = null;
                $unmatched[] = $entry;
            }
        }

        return ['exact' => $exact, 'probable' => $probable, 'unmatched' => $unmatched];
    }

    /**
     * @param  list<array<string, mixed>>  $unmatched
     * @param  list<array{description?: string}>  $rows
     * @return list<array<string, mixed>>
     */
    private function attachCustomerSuggestions(array $unmatched, array $rows): array
    {
        $descriptions = [];
        foreach ($unmatched as $index => $entry) {
            $description = mb_strtolower(trim((string) ($rows[$entry['row']]['description'] ?? '')));
            if ($description !== '') {
                $descriptions[$index] = $description;
            }
        }

        if ($descriptions === []) {
            return $unmatched;
        }

        foreach (Customer::query()->commercial()->where('status', 'approved')->orderBy('id')->cursor(['id', 'name', 'company_name']) as $customer) {
            $displayName = $customer->company_name ?: $customer->name;
            $needle = mb_strtolower(trim((string) $displayName));
            if ($needle === '' || mb_strlen($needle) < self::MIN_CUSTOMER_SUGGESTION_LENGTH) {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';
            foreach ($descriptions as $index => $description) {
                if (str_contains($description, $needle) && preg_match($pattern, $description) === 1) {
                    $unmatched[$index]['suggestion'] = ['customer_id' => $customer->id, 'name' => $displayName];
                    unset($descriptions[$index]);
                }
            }

            if ($descriptions === []) {
                break;
            }
        }

        return $unmatched;
    }
}
