<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CustomerMessageBudget
{
    public function claim(string $scope, bool $sms = true): bool
    {
        $now = now(config('app.business_timezone', 'Africa/Cairo'));
        $date = $now->toDateString();
        $budgets = $sms ? [
            'sms:global:day' => (int) config('auth.customer_sms_daily_limit', 1000),
            'sms:global:hour:'.$now->format('H') => (int) config('auth.customer_sms_hourly_limit', 100),
        ] : [];
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
                if (str_starts_with($scope, 'sms:global:') && $sends < (int) ceil($limit * 0.8) && $sends + 1 >= (int) ceil($limit * 0.8)) {
                    DB::afterCommit(fn () => logger()->warning('Customer SMS budget reached 80%.', ['scope' => $scope, 'date' => $date, 'limit' => $limit]));
                }
            }

            return true;
        });
    }
}
