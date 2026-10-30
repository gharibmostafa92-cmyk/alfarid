<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Manages daily and hourly outbound SMS/message rate-limit budgets.
 * Tracks usage in customer_message_budgets table using atomic pessimistic row locks.
 */
class CustomerMessageBudget
{
    /**
     * Atomically claim a messaging quota slot for the given scope and channel.
     * Enforces global hourly/daily SMS quotas as well as per-recipient limits.
     *
     * @param  string  $scope  Distinct scope identifier (e.g. "verify:<phone_hash>" or "reset:customer:<id>")
     * @param  bool  $sms  Whether this message consumes global SMS quota
     */
    public function claim(string $scope, bool $sms = true, ?string $networkKey = null): bool
    {
        $now = now(config('app.business_timezone', 'Africa/Cairo'));
        $date = $now->toDateString();
        $budgets = $sms ? [
            'sms:global:day' => (int) config('auth.customer_sms_daily_limit', 1000),
            'sms:global:hour:'.$now->format('H') => (int) config('auth.customer_sms_hourly_limit', 100),
        ] : [];
        if ($sms && str_starts_with($scope, 'verify:')) {
            $fraction = max(0, min(0.6, (float) config('auth.customer_sms_verification_fraction', 0.6)));
            $budgets['sms:verify:day'] = (int) floor($budgets['sms:global:day'] * $fraction);
            $budgets['sms:verify:hour:'.$now->format('H')] = (int) floor($budgets['sms:global:hour:'.$now->format('H')] * $fraction);
            if ($networkKey !== null) {
                $budgets['sms:verify:network:'.$networkKey] = max(0, (int) config('auth.customer_sms_verification_network_daily_limit', 10));
            }
        }
        $budgets[$scope] = (int) config('auth.customer_daily_messages', 5);

        return DB::transaction(function () use ($budgets, $date): bool {
            $claims = [];
            foreach ($budgets as $scope => $limit) {
                $key = hash('sha256', $scope);
                DB::table('customer_message_budgets')->insertOrIgnore(['key' => $key, 'date' => $date, 'sends' => 0]);
                $query = DB::table('customer_message_budgets')->where('key', $key)->where('date', $date);
                $row = (clone $query)->lockForUpdate()->first();
                if ($row->sends >= $limit) {
                    return false;
                }
                $claims[] = [$query, $scope, (int) $row->sends, $limit];
            }
            foreach ($claims as [$query, $scope, $sends, $limit]) {
                $query->increment('sends');
                if (str_starts_with($scope, 'sms:') && $sends + 1 === $limit) {
                    DB::afterCommit(fn () => logger()->warning('Customer SMS budget exhausted.', ['scope' => $scope, 'date' => $date, 'limit' => $limit]));
                }
                if (str_starts_with($scope, 'sms:global:') && $sends < (int) ceil($limit * 0.8) && $sends + 1 >= (int) ceil($limit * 0.8)) {
                    DB::afterCommit(fn () => logger()->warning('Customer SMS budget reached 80%.', ['scope' => $scope, 'date' => $date, 'limit' => $limit]));
                }
            }

            return true;
        });
    }
}
