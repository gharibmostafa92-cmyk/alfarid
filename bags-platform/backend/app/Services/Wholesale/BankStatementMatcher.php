<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\WholesalePaymentNotice;
use Carbon\Carbon;

/**
 * Reconciles parsed bank statement credit transactions against pending wholesale payment notices.
 */
final class BankStatementMatcher
{
    /**
     * @param  list<array{amount: float|string, reference?: string, date?: string, description?: string}>  $rows
     * @return array{exact: list<array<string, mixed>>, probable: list<array<string, mixed>>, unmatched: list<array<string, mixed>>}
     */
    public function match(array $rows): array
    {
        $notices = WholesalePaymentNotice::query()
            ->with('customer:id,name,company_name')
            ->where('status', WholesalePaymentNoticeStatus::PendingReview)
            ->get();

        $exact = [];
        $probable = [];
        $unmatched = [];
        foreach ($rows as $index => $row) {
            $amount = number_format((float) ($row['amount'] ?? 0), 2, '.', '');
            $reference = mb_strtolower(trim((string) ($row['reference'] ?? '')));
            $date = isset($row['date']) ? Carbon::parse((string) $row['date'])->startOfDay() : null;
            $hit = null;
            $kind = null;
            foreach ($notices as $notice) {
                $sameAmount = number_format((float) $notice->amount, 2, '.', '') === $amount;
                $sameReference = $reference !== '' && mb_strtolower(trim((string) $notice->reference_number)) === $reference;
                $closeDate = $date !== null && abs($date->diffInDays($notice->transfer_date?->copy()->startOfDay() ?? $date, true)) <= 1;
                if ($sameAmount && $sameReference && $closeDate) {
                    $hit = $notice;
                    $kind = 'exact';
                    break;
                }
                if ($hit === null && $sameAmount && ($sameReference || $closeDate)) {
                    $hit = $notice;
                    $kind = 'probable';
                }
            }
            $entry = [
                'row' => $index,
                'amount' => $amount,
                'reference' => (string) ($row['reference'] ?? ''),
                'notice_id' => $hit?->id,
                'notice_number' => $hit?->notice_number,
            ];
            if ($kind === 'exact') {
                $exact[] = $entry;
            } elseif ($kind === 'probable') {
                $probable[] = $entry;
            } else {
                $entry['suggestion'] = $this->suggestCustomer((string) ($row['description'] ?? ''));
                $unmatched[] = $entry;
            }
        }

        return ['exact' => $exact, 'probable' => $probable, 'unmatched' => $unmatched];
    }

    /** @return array{customer_id: int, name: string}|null */
    private function suggestCustomer(string $description): ?array
    {
        $description = mb_strtolower(trim($description));
        if ($description === '') {
            return null;
        }
        $customers = Customer::query()->whereIn('type', ['wholesale', 'distributor'])->limit(200)->get(['id', 'name', 'company_name']);
        foreach ($customers as $customer) {
            $name = mb_strtolower((string) ($customer->company_name ?: $customer->name));
            if ($name !== '' && str_contains($description, $name)) {
                return ['customer_id' => $customer->id, 'name' => $customer->company_name ?: $customer->name];
            }
        }

        return null;
    }
}
