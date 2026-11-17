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

/**
 * Reconciles parsed bank statement credit transactions against pending wholesale payment notices.
 */
final class BankStatementMatcher
{
    public static function cleanAmount(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '0.00';
        }
        $str = trim((string) $amount);
        $arabicIndic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $digits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $str = str_replace($arabicIndic, $digits, $str);

        if (preg_match('/\b(dr|debit|مدين)\b/iu', $str) === 1) {
            return '0.00';
        }
        if (preg_match('/-\s*\d/u', $str) === 1 || str_ends_with($str, '-') || (str_starts_with($str, '(') && str_ends_with($str, ')'))) {
            return '0.00';
        }

        $str = preg_replace('/\b(egp|le|l\.e\.?)\b/iu', '', $str) ?? $str;
        $str = str_replace(['ج.م.', 'ج.م', 'ج م'], '', $str);
        $str = ltrim($str, " \t.");

        // Normalize European decimal commas (e.g. "1.500,50" -> "1500.50" or "1500,50" -> "1500.50") vs US thousands commas ("1,500.00").
        $lastComma = strrpos($str, ',');
        $lastDot = strrpos($str, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $afterComma = preg_replace('/\D/', '', substr($str, $lastComma + 1)) ?? '';
            if (strlen($afterComma) <= 2) {
                $str = str_replace('.', '', substr($str, 0, $lastComma)).'.'.$afterComma;
            }
        } elseif ($lastComma === false && preg_match('/^\s*\d{1,3}(?:\.\d{3})+\s*$/', $str) === 1) {
            $str = str_replace('.', '', $str);
        }

        $str = preg_replace('/[^\d.]/', '', $str) ?? '';
        if ($str === '' || $str === '.' || substr_count($str, '.') > 1) {
            return '0.00';
        }
        try {
            $decimal = BigDecimal::of($str)->toScale(2, RoundingMode::HalfUp);

            return $decimal->isLessThanOrEqualTo('0') ? '0.00' : (string) $decimal;
        } catch (\Throwable) {
            return '0.00';
        }
    }

    public static function cleanReference(?string $reference): string
    {
        if ($reference === null) {
            return '';
        }
        $ref = mb_strtolower(trim($reference));
        $arabicIndic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', 'أ', 'إ', 'آ', 'ٱ', 'ة', 'ى'];
        $replacements = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'ا', 'ا', 'ا', 'ا', 'ه', 'ي'];
        $ref = str_replace($arabicIndic, $replacements, $ref);
        $ref = preg_replace('/[\s.,_()\/#\\-]+/u', '', $ref) ?? '';

        return $ref;
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
        if (count($rows) > 500) {
            throw new BusinessException('كشف البنك يتجاوز 500 حركة؛ قسّمه إلى ملفات أصغر.', [], 422);
        }
        $amounts = array_values(array_unique(array_filter(
            array_map(fn ($row) => self::cleanAmount($row['amount'] ?? 0), $rows),
            static fn (string $amount): bool => BigDecimal::of($amount)->isGreaterThan('0'),
        )));
        $notices = WholesalePaymentNotice::query()
            ->with('customer:id,name,company_name')
            ->where('status', WholesalePaymentNoticeStatus::PendingReview)
            ->whereIn('amount', $amounts)
            ->orderBy('id')
            ->limit(2001)
            ->get();
        if ($notices->count() > 2000) {
            throw new BusinessException('عدد الإشعارات المرشحة كبير جدًا؛ قسّم كشف البنك إلى ملفات أصغر.', [], 422);
        }

        $preparedNoticesByAmount = $notices->map(fn ($n) => [
            'model' => $n,
            'amount' => self::cleanAmount($n->amount),
            'reference' => self::cleanReference($n->reference_number),
            'date' => $n->transfer_date?->copy()->startOfDay(),
        ])->groupBy('amount');

        $parsedRows = [];
        foreach ($rows as $index => $row) {
            try {
                $date = isset($row['date']) ? Carbon::parse((string) $row['date'])->startOfDay() : null;
            } catch (\Throwable) {
                // A garbage row date degrades to reference/amount matching
                // instead of aborting the whole reconciliation.
                $date = null;
            }
            $parsedRows[$index] = [
                'index' => $index,
                'amount' => self::cleanAmount($row['amount'] ?? 0),
                'reference' => self::cleanReference((string) ($row['reference'] ?? '')),
                'raw_reference' => (string) ($row['reference'] ?? ''),
                'date' => $date,
            ];
        }

        $rowResults = [];
        $claimedNoticeIds = [];

        // Pass 1: classify all exact matches first using amount-indexed buckets.
        foreach ($parsedRows as $index => $parsed) {
            if ($parsed['reference'] === '' || BigDecimal::of($parsed['amount'])->isLessThanOrEqualTo('0')) {
                continue;
            }
            foreach ($preparedNoticesByAmount->get($parsed['amount'], collect()) as $prepared) {
                if (isset($claimedNoticeIds[$prepared['model']->id])) {
                    continue;
                }
                $sameReference = $prepared['reference'] === $parsed['reference'];
                $closeDate = $parsed['date'] !== null && $prepared['date'] !== null
                    && abs($parsed['date']->diffInDays($prepared['date'], true)) <= 1;
                if ($sameReference && $closeDate) {
                    $rowResults[$index] = ['kind' => 'exact', 'model' => $prepared['model']];
                    $claimedNoticeIds[$prepared['model']->id] = true;
                    break;
                }
            }
        }

        // Pass 2: match remaining rows as probable or unmatched.
        foreach ($parsedRows as $index => $parsed) {
            if (isset($rowResults[$index])) {
                continue;
            }
            if (BigDecimal::of($parsed['amount'])->isLessThanOrEqualTo('0')) {
                $rowResults[$index] = ['kind' => 'unmatched', 'model' => null];

                continue;
            }
            $hit = null;
            foreach ($preparedNoticesByAmount->get($parsed['amount'], collect()) as $prepared) {
                if (isset($claimedNoticeIds[$prepared['model']->id])) {
                    continue;
                }
                $bothHaveRef = $parsed['reference'] !== '' && $prepared['reference'] !== '';
                $sameReference = $bothHaveRef && ($prepared['reference'] === $parsed['reference']);
                $conflictingReferences = $bothHaveRef && ! $sameReference;
                $closeDate = $parsed['date'] !== null && $prepared['date'] !== null
                    && abs($parsed['date']->diffInDays($prepared['date'], true)) <= 1;
                if ($sameReference || (! $conflictingReferences && $closeDate)) {
                    $hit = $prepared['model'];
                    $claimedNoticeIds[$prepared['model']->id] = true;
                    break;
                }
            }
            $rowResults[$index] = $hit !== null
                ? ['kind' => 'probable', 'model' => $hit]
                : ['kind' => 'unmatched', 'model' => null];
        }

        $exact = [];
        $probable = [];
        $unmatched = [];
        foreach ($parsedRows as $index => $parsed) {
            $res = $rowResults[$index];
            $hit = $res['model'];
            $entry = [
                'row' => $index,
                'amount' => $parsed['amount'],
                'reference' => $parsed['raw_reference'],
                'notice_id' => $hit?->id,
                'notice_number' => $hit?->notice_number,
            ];
            if ($res['kind'] === 'exact') {
                $exact[] = $entry;
            } elseif ($res['kind'] === 'probable') {
                $probable[] = $entry;
            } else {
                $entry['suggestion'] = null;
                $unmatched[] = $entry;
            }
        }

        $descriptions = [];
        foreach ($unmatched as $index => $entry) {
            $description = mb_strtolower(trim((string) ($rows[$entry['row']]['description'] ?? '')));
            if ($description !== '') {
                $descriptions[$index] = $description;
            }
        }
        if ($descriptions !== []) {
            foreach (Customer::query()->commercial()->where('status', 'approved')->orderBy('id')->cursor(['id', 'name', 'company_name']) as $customer) {
                $name = mb_strtolower(trim((string) ($customer->company_name ?: $customer->name)));
                if ($name === '' || mb_strlen($name) < 4) {
                    continue;
                }
                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u';
                foreach ($descriptions as $index => $description) {
                    if (! str_contains($description, $name)) {
                        continue;
                    }
                    if (preg_match($pattern, $description) === 1) {
                        $unmatched[$index]['suggestion'] = ['customer_id' => $customer->id, 'name' => $customer->company_name ?: $customer->name];
                        unset($descriptions[$index]);
                    }
                }
                if ($descriptions === []) {
                    break;
                }
            }
        }

        return ['exact' => $exact, 'probable' => $probable, 'unmatched' => $unmatched];
    }
}
