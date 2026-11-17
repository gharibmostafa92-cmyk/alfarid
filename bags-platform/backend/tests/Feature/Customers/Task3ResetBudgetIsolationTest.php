<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Services\Auth\CustomerAuthService;
use App\Services\Customer\CustomerMessageBudget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// task3 HIGH-02: reset SMS budget isolation (bulkhead) + pinned refund timestamps.
class Task3ResetBudgetIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reset_sms_is_capped_by_network_before_global_budget(): void
    {
        config([
            'auth.customer_sms_daily_limit' => 1000,
            'auth.customer_sms_hourly_limit' => 100,
            'auth.customer_daily_messages' => 1000,
        ]);
        $budget = app(CustomerMessageBudget::class);
        $netKey = CustomerMessageBudget::subnetKey('203.0.113.5');

        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue($budget->claim('reset:customer:'.$i, sms: true, networkKey: $netKey));
        }

        // 4th reset SMS from the same subnet in the same hour exceeds the
        // network hourly cap (3) while the global budget is nearly untouched.
        $this->assertFalse($budget->claim('reset:customer:4', sms: true, networkKey: $netKey));
        $this->assertSame(3, (int) DB::table('customer_message_budgets')
            ->where('key', hash('sha256', 'sms:global:day'))->value('sends'));
    }

    public function test_reset_sms_day_cap_is_a_fraction_of_global_budget(): void
    {
        config([
            'auth.customer_sms_daily_limit' => 100,
            'auth.customer_sms_hourly_limit' => 100,
            'auth.customer_daily_messages' => 1000,
        ]);
        $budget = app(CustomerMessageBudget::class);

        // Distinct subnets bypass the network cap; the 30% reset day/hour
        // bulkhead (30 of 100) must still stop the 31st claim.
        for ($i = 1; $i <= 30; $i++) {
            $netKey = CustomerMessageBudget::subnetKey('10.'.($i >> 8).'.'.($i & 255).'.7');
            $this->assertTrue($budget->claim('reset:customer:'.$i, sms: true, networkKey: $netKey));
        }

        $netKey = CustomerMessageBudget::subnetKey('10.9.9.9');
        $this->assertFalse($budget->claim('reset:customer:31', sms: true, networkKey: $netKey));
    }

    public function test_reset_sms_claim_is_fail_closed_without_a_network_key(): void
    {
        $this->assertFalse(app(CustomerMessageBudget::class)->claim('reset:customer:1', sms: true));
    }

    public function test_delivery_failure_refunds_the_claim_hour_not_the_failure_hour(): void
    {
        $at = CarbonImmutable::parse('2026-11-02 14:59:58', 'Africa/Cairo');
        $netKey = CustomerMessageBudget::subnetKey('203.0.113.9');
        $customer = Customer::factory()->create();
        $key = $customer->getEmailForPasswordReset();
        $oldPlain = str_repeat('a', 64);

        $budget = app(CustomerMessageBudget::class);
        $this->assertTrue($budget->claim('reset:customer:'.$customer->id, sms: true, networkKey: $netKey, at: $at));

        // A concurrent re-request issues a newer token before the failure cleanup runs.
        $newPlain = str_repeat('b', 64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $key],
            ['token' => hash('sha256', $newPlain), 'created_at' => $at->addMinutes(2)->toDateTimeString()],
        );

        try {
            app(CustomerAuthService::class)->handleResetDeliveryFailure(
                $customer, $key, $oldPlain, true, $netKey, $at, new BusinessException('boom.', [], 503)
            );
            $this->fail('The delivery failure must be rethrown for the job retry.');
        } catch (BusinessException) {
        }

        // Newer token survives the stale cleanup (compare-and-delete).
        $this->assertSame(hash('sha256', $newPlain), (string) DB::table('password_reset_tokens')->where('email', $key)->value('token'));

        // The 14:00-hour counters are refunded; the 15:00-hour counters are untouched.
        $this->assertSame(0, (int) DB::table('customer_message_budgets')
            ->where('key', hash('sha256', 'sms:global:hour:14'))->where('date', '2026-11-02')->value('sends'));
        $this->assertDatabaseMissing('customer_message_budgets', [
            'key' => hash('sha256', 'sms:global:hour:15'), 'date' => '2026-11-02',
        ]);
        $this->assertDatabaseMissing('customer_message_budgets', [
            'key' => hash('sha256', 'sms:reset:hour:15'), 'date' => '2026-11-02',
        ]);
    }
}
