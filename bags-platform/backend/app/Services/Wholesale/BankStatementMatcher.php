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

        $preparedNoticesByAmount = $notices->map(fn ($n) => [
            'model' => $n,
            'amount' => number_format((float) $n->amount, 2, '.', ''),
            'reference' => mb_strtolower(trim((string) $n->reference_number)),
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
                'amount' => number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                'reference' => mb_strtolower(trim((string) ($row['reference'] ?? ''))),
                'raw_reference' => (string) ($row['reference'] ?? ''),
                'date' => $date,
            ];
        }

        $rowResults = [];
        $claimedNoticeIds = [];

        // Pass 1: classify all exact matches first using amount-indexed buckets.
        foreach ($parsedRows as $index => $parsed) {
            if ($parsed['reference'] === '') {
                continue;
            }
            foreach ($preparedNoticesByAmount->get($parsed['amount'], collect()) as $prepared) {
                if (isset($claimedNoticeIds[$prepared['model']->id])) {
                    continue;
                }
                $sameReference = $prepared['reference'] === $parsed['reference'];
                $closeDate = $parsed['date'] !== null && abs($parsed['date']->diffInDays($prepared['date'] ?? $parsed['date'], true)) <= 1;
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
            $hit = null;
            foreach ($preparedNoticesByAmount->get($parsed['amount'], collect()) as $prepared) {
                if (isset($claimedNoticeIds[$prepared['model']->id])) {
                    continue;
                }
                $sameReference = $parsed['reference'] !== '' && $prepared['reference'] === $parsed['reference'];
                $closeDate = $parsed['date'] !== null && abs($parsed['date']->diffInDays($prepared['date'] ?? $parsed['date'], true)) <= 1;
                if ($sameReference || $closeDate) {
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
