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
        if ($reference !== '' && mb_strtolower($reference) === mb_strtolower(trim($stated['reference']))) {
            $score += 30;
        }
        if ($date !== null) {
            $statedDate = Carbon::parse($stated['date'])->startOfDay();
            // Allow a 1-day tolerance (15%) for interbank transfer cutoff times and weekend clearing delays.
            if (abs($statedDate->diffInDays(Carbon::parse($date)->startOfDay(), true)) <= 1) {
                $score += 15;
            }
        }
        // Substring comparison (5%) accommodates abbreviations and informal bank names (e.g. CIB vs Commercial Int Bank).
        if ($bank !== '' && (mb_stripos($stated['bank'], $bank) !== false || mb_stripos($bank, $stated['bank']) !== false)) {
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
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }
        if (! is_string($value) || ! preg_match('/^\d+(\.\d{1,2})?$/', trim($value))) {
            return null;
        }

        return number_format((float) trim($value), 2, '.', '');
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
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
