<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Manages daily and hourly outbound SMS/messaging rate-limit budgets.
 *
 * Tracks usage in the customer_message_budgets table using pessimistic row locks.
 */
class CustomerMessageBudget
{
    /**
     * Generate an HMAC fingerprint of the client IP subnet (/24 for IPv4, /48 for IPv6).
     *
     * @param  string|null  $ip  Raw client IP address.
     * @return string|null Anonymized subnet fingerprint, or null if the IP is invalid.
     */
    public static function subnetKey(?string $ip): ?string
    {
        $bin = $ip ? @inet_pton($ip) : false;
        if ($bin === false) {
            return null;
        }

        // Mask to /24 (IPv4) or /48 (IPv6) to prevent rate-limit evasion via cheap /64 VPS address rotation.
        $bin = strlen($bin) === 4 ? substr($bin, 0, 3)."\0" : substr($bin, 0, 6).str_repeat("\0", 10);

        return hash_hmac('sha256', (string) inet_ntop($bin), (string) config('app.key'));
    }

    /**
     * Atomically claim a messaging quota slot for the given scope and channel.
     *
     * @param  string  $scope  Distinct scope identifier (e.g. "verify:<phone_hash>" or "reset:customer:<id>").
     * @param  bool  $sms  Whether this message consumes global SMS quota.
     * @param  string|null  $networkKey  Subnet key to enforce network-level verification caps.
     * @return bool True if all quota slots were successfully acquired; false if exhausted.
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

            // Fail closed if network key is missing to prevent bypassing subnet caps.
            if ($networkKey === null) {
                logger()->warning('Verification SMS claimed without network key.');

                return false;
            }
            $budgets['sms:verify:network:'.$networkKey] = max(0, (int) config('auth.customer_sms_verification_network_daily_limit', 10));
            $budgets['sms:verify:network-hour:'.$now->format('H').':'.$networkKey] = max(0, (int) config('auth.customer_sms_verification_network_hourly_limit', 3));
        }
        $budgets[$scope] = (int) config('auth.customer_daily_messages', 5);

        return DB::transaction(function () use ($budgets, $date): bool {
            $claims = [];
            foreach ($budgets as $scope => $limit) {
                $key = hash('sha256', $scope);
                DB::table('customer_message_budgets')->insertOrIgnore(['key' => $key, 'date' => $date, 'sends' => 0]);
                $query = DB::table('customer_message_budgets')->where('key', $key)->where('date', $date);

                // Acquire pessimistic write lock to prevent race conditions and overdrafts under concurrency.
                $row = (clone $query)->lockForUpdate()->first();
                if ($row->sends >= $limit) {
                    return false;
                }
                $claims[] = [$query, $scope, (int) $row->sends, $limit];
            }
            foreach ($claims as [$query, $scope, $sends, $limit]) {
                $query->increment('sends');

                // Defer warnings until commit to avoid holding database row locks during logging.
                if (str_starts_with($scope, 'sms:') && $sends + 1 === $limit) {
                    DB::afterCommit(fn () => logger()->warning('Customer SMS budget exhausted.', ['scope' => $scope, 'date' => $date, 'limit' => $limit]));
                }
                foreach ([0.5, 0.8] as $ratio) {
                    $mark = (int) ceil($limit * $ratio);
                    if ((str_starts_with($scope, 'sms:global:') || str_starts_with($scope, 'sms:verify:day')) && $sends < $mark && $sends + 1 >= $mark) {
                        DB::afterCommit(fn () => logger()->warning('Customer SMS budget reached '.($ratio * 100).'%.', ['scope' => $scope, 'date' => $date, 'limit' => $limit]));
                    }
                }
            }

            return true;
        });
    }
}
