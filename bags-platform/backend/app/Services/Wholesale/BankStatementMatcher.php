<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\WholesalePaymentNotice;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reconciles parsed bank statement credit transactions against pending wholesale payment notices.
 */
final class BankStatementMatcher
{
    /** @var Collection<int, Customer>|null */
    private ?Collection $cachedCustomers = null;

    /**
     * Classifies statement credit transactions into exact, probable, or unmatched buckets against pending payment notices.
     *
     * @param  list<array{amount: float|string, reference?: string, date?: string, description?: string}>  $rows  Statement rows.
     * @return array{exact: list<array<string, mixed>>, probable: list<array<string, mixed>>, unmatched: list<array<string, mixed>>} Categorized reconciliations.
     */
    public function match(array $rows): array
    {
        $notices = WholesalePaymentNotice::query()
            ->with('customer:id,name,company_name')
            ->where('status', WholesalePaymentNoticeStatus::PendingReview)
            ->get();

        $preparedNotices = $notices->map(fn ($n) => [
            'model' => $n,
            'amount' => number_format((float) $n->amount, 2, '.', ''),
            'reference' => mb_strtolower(trim((string) $n->reference_number)),
            'date' => $n->transfer_date?->copy()->startOfDay(),
        ]);

        $exact = [];
        $probable = [];
        $unmatched = [];
        foreach ($rows as $index => $row) {
            $amount = number_format((float) ($row['amount'] ?? 0), 2, '.', '');
            $reference = mb_strtolower(trim((string) ($row['reference'] ?? '')));
            try {
                $date = isset($row['date']) ? Carbon::parse((string) $row['date'])->startOfDay() : null;
            } catch (\Throwable) {
                // A garbage row date degrades to reference/amount matching
                // instead of aborting the whole reconciliation.
                $date = null;
            }
            $hit = null;
            $kind = null;
            foreach ($preparedNotices as $prepared) {
                $sameAmount = $prepared['amount'] === $amount;
                $sameReference = $reference !== '' && $prepared['reference'] === $reference;
                $closeDate = $date !== null && abs($date->diffInDays($prepared['date'] ?? $date, true)) <= 1;
                // Exact match requires matching amount, sanitized reference, and date within a 1-day banking window.
                if ($sameAmount && $sameReference && $closeDate) {
                    $hit = $prepared['model'];
                    $kind = 'exact';
                    break;
                }
                // Probable match pairs matching amounts having either identical reference or close date.
                if ($hit === null && $sameAmount && ($sameReference || $closeDate)) {
                    $hit = $prepared['model'];
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

    /**
     * Fallback lookup: scans statement narration text against customer trade names to suggest sender.
     *
     * @return array{customer_id: int, name: string}|null
     */
    private function suggestCustomer(string $description): ?array
    {
        $description = mb_strtolower(trim($description));
        if ($description === '') {
            return null;
        }
        $customers = $this->cachedCustomers ??= Customer::query()
            ->commercial()
            ->limit(200)
            ->get(['id', 'name', 'company_name']);

        foreach ($customers as $customer) {
            $name = mb_strtolower((string) ($customer->company_name ?: $customer->name));
            if ($name !== '' && str_contains($description, $name)) {
                return ['customer_id' => $customer->id, 'name' => $customer->company_name ?: $customer->name];
            }
        }

        return null;
    }
}
