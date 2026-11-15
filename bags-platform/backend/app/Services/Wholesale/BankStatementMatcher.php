<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\WholesalePaymentNotice;
use Carbon\Carbon;

/**
 * Reconciles parsed bank statement credit transactions against pending wholesale payment notices.
 */
final class BankStatementMatcher
{
    /**
     * Classifies statement credit transactions into exact, probable, or unmatched buckets against pending payment notices.
     *
     * @param  list<array{amount: float|string, reference?: string, date?: string, description?: string}>  $rows  Statement rows.
     * @return array{exact: list<array<string, mixed>>, probable: list<array<string, mixed>>, unmatched: list<array<string, mixed>>} Categorized reconciliations.
     */
    public function match(array $rows): array
    {
        if (count($rows) > 500) {
            throw new \App\Exceptions\BusinessException('كشف البنك يتجاوز 500 حركة؛ قسّمه إلى ملفات أصغر.', [], 422);
        }
        $amounts = array_values(array_unique(array_map(fn ($row) => number_format((float) ($row['amount'] ?? 0), 2, '.', ''), $rows)));
        $notices = WholesalePaymentNotice::query()
            ->with('customer:id,name,company_name')
            ->where('status', WholesalePaymentNoticeStatus::PendingReview)
            ->whereIn('amount', $amounts)
            ->orderBy('id')
            ->limit(2001)
            ->get();
        if ($notices->count() > 2000) {
            throw new \App\Exceptions\BusinessException('عدد الإشعارات المرشحة كبير جدًا؛ قسّم كشف البنك إلى ملفات أصغر.', [], 422);
        }

        $preparedNotices = $notices->map(fn ($n) => [
            'model' => $n,
            'amount' => number_format((float) $n->amount, 2, '.', ''),
            'reference' => mb_strtolower(trim((string) $n->reference_number)),
            'date' => $n->transfer_date?->copy()->startOfDay(),
        ]);

        $exact = [];
        $probable = [];
        $unmatched = [];
        $matchedNoticeIds = [];
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
                if (isset($matchedNoticeIds[$prepared['model']->id])) {
                    continue;
                }
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
            if ($hit !== null) {
                $matchedNoticeIds[$hit->id] = true;
            }
            if ($kind === 'exact') {
                $exact[] = $entry;
            } elseif ($kind === 'probable') {
                $probable[] = $entry;
            } else {
                $entry['suggestion'] = null;
                $unmatched[] = $entry;
            }
        }

        $descriptions = [];
        foreach ($unmatched as $index => $entry) {
            $description = mb_strtolower(trim((string) ($rows[$entry['row']]['description'] ?? '')));
            if ($description !== '') $descriptions[$index] = $description;
        }
        if ($descriptions !== []) {
            foreach (Customer::query()->commercial()->orderBy('id')->cursor(['id', 'name', 'company_name']) as $customer) {
                $name = mb_strtolower((string) ($customer->company_name ?: $customer->name));
                if ($name === '') continue;
                foreach ($descriptions as $index => $description) {
                    if (str_contains($description, $name)) {
                        $unmatched[$index]['suggestion'] = ['customer_id' => $customer->id, 'name' => $customer->company_name ?: $customer->name];
                        unset($descriptions[$index]);
                    }
                }
                if ($descriptions === []) break;
            }
        }

        return ['exact' => $exact, 'probable' => $probable, 'unmatched' => $unmatched];
    }
}
