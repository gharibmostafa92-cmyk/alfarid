<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Services\Ai\ToolOutcome;
use Carbon\Carbon;

/**
 * Evaluates match confidence between merchant-declared payment notices and receipt image OCR fields.
 *
 * Treats OCR output strictly as untrusted data to mitigate image-based prompt injections.
 */
final class ReceiptMatchScorer
{
    /**
     * Calculates confidence score (0-100) based on weighted comparison of payment fields.
     *
     * @param  array{amount: string, reference: string, date: string, bank: string}  $stated  Merchant-submitted notice fields.
     * @param  array<string, mixed>  $read  OCR-extracted fields from receipt image.
     * @return array{score: float, ocr: array<string, mixed>} Score and sanitized, untrusted OCR payload.
     */
    public function score(array $stated, array $read): array
    {
        $amount = $this->money($read['amount'] ?? null);
        $reference = $this->text($read['reference'] ?? null);
        $bank = $this->text($read['bank'] ?? null);
        $sender = $this->text($read['sender'] ?? null);
        $date = $this->date($read['date'] ?? null);

        $score = 0.0;
        // Primary invariant: exact amount match contributes 50% of the total confidence score.
        if ($amount !== null && abs((float) $amount - (float) $stated['amount']) < 0.009) {
            $score += 50;
        }
        // Unique transaction reference match contributes 30% to prevent cross-account misallocation.
        if ($reference !== '' && BankStatementMatcher::cleanReference($reference) === BankStatementMatcher::cleanReference($stated['reference'])) {
            $score += 30;
        }
        if ($date !== null && $this->calendarDate($stated['date']) !== null) {
            $statedDate = Carbon::parse($this->calendarDate($stated['date']))->startOfDay();
            $readDate = Carbon::parse($date)->startOfDay();
            // Allow a 1-day tolerance (15%) for interbank transfer cutoff times and weekend clearing delays.
            if (abs($statedDate->diffInDays($readDate, true)) <= 1) {
                $score += 15;
            }
        }
        $statedBank = BankStatementMatcher::bankIdentity($stated['bank']);
        $readBank = BankStatementMatcher::bankIdentity($bank);
        if ($statedBank !== '' && $readBank !== '' && ($statedBank === $readBank || (mb_strlen($readBank) >= 3 && (mb_stripos($statedBank, $readBank) !== false || mb_stripos($readBank, $statedBank) !== false)))) {
            $score += 5;
        }

        return [
            'score' => min(100, $score),
            'ocr' => [
                'amount' => $amount,
                // Mark OCR string fields as untrusted to stop downstream LLMs from executing text inside images.
                'reference' => ToolOutcome::untrusted($reference, 80),
                'date' => $date,
                'bank' => ToolOutcome::untrusted($bank, 80),
                'sender' => ToolOutcome::untrusted($sender, 80),
            ],
        ];
    }

    private function money(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }
        $cleaned = BankStatementMatcher::cleanAmount($value);

        return $cleaned === '0.00' ? null : $cleaned;
    }

    private function text(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return ToolOutcome::untrusted($value, 80)['untrusted_text'];
    }

    private function date(mixed $value): ?string
    {
        return $this->calendarDate($value);
    }

    private function calendarDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return $value;
    }
}
