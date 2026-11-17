<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Services\Customer\CustomerMessageBudget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task6 Lane A5: message-budget subnet mapping + write discipline (F10).
 *
 * F10: IPv4-mapped IPv6 client addresses (::ffff:a.b.c.d) must map into
 * their real IPv4 /24 instead of collapsing every dual-stack client into a
 * single network bucket (which false-positive locks out the whole platform
 * after 3 messages). And a claim rejected on an exhausted bucket must not
 * insert new scope rows (disk-bloat DoS via random scopes).
 */
class Task6MessageBudgetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_mapped_ipv4_uses_real_ipv4_subnet(): void
    {
        $mapped = CustomerMessageBudget::subnetKey('::ffff:192.0.2.128');
        $plain = CustomerMessageBudget::subnetKey('192.0.2.200');
        $otherSubnet = CustomerMessageBudget::subnetKey('::ffff:198.51.100.7');

        $this->assertNotNull($mapped);
        $this->assertSame($plain, $mapped, '::ffff:192.0.2.128 must share the 192.0.2.0/24 bucket.');
        $this->assertNotSame($mapped, $otherSubnet);
    }

    public function test_rejected_claim_does_not_insert_new_scope_rows(): void
    {
        $budget = app(CustomerMessageBudget::class);
        $networkKey = CustomerMessageBudget::subnetKey('192.0.2.44');
        $this->assertNotNull($networkKey);
        $at = CarbonImmutable::now(config('app.business_timezone', 'Africa/Cairo'));

        // Exhaust the default network-hour bucket (limit 3) with 3 scopes.
        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue($budget->claim('verify:hash'.$i, true, $networkKey, $at));
        }
        $this->assertFalse($budget->claim('verify:hash-overflow', true, $networkKey, $at));

        $rowsBefore = DB::table('customer_message_budgets')->count();
        $this->assertFalse($budget->claim('verify:hash-new-scope', true, $networkKey, $at));
        $this->assertSame($rowsBefore, DB::table('customer_message_budgets')->count(), 'Rejected claims must not write new rows.');
    }

    public function test_verification_refund_uses_the_claim_day_and_hour_across_midnight(): void
    {
        $budget = app(CustomerMessageBudget::class);
        $networkKey = CustomerMessageBudget::subnetKey('192.0.2.44');
        $claimAt = CarbonImmutable::parse('2026-11-17 23:59:50', config('app.business_timezone', 'Africa/Cairo'));
        $nextDay = $claimAt->addSeconds(20);

        $this->assertNotNull($networkKey);
        $this->assertTrue($budget->claim('verify:phone-before-midnight', true, $networkKey, $claimAt));
        $this->assertTrue($budget->claim('verify:phone-after-midnight', true, $networkKey, $nextDay));

        // The sender must refund with the timestamp captured when it claimed,
        // even if the network operation completes after midnight.
        $budget->releaseVerificationClaim('phone-before-midnight', $networkKey, $claimAt);

        $scopeKey = static fn (string $scope): string => hash('sha256', $scope);
        $oldDayRows = DB::table('customer_message_budgets')
            ->where('date', $claimAt->toDateString())
            ->whereIn('key', [
                $scopeKey('sms:global:day'),
                $scopeKey('sms:global:hour:23'),
                $scopeKey('sms:verify:day'),
                $scopeKey('sms:verify:hour:23'),
                $scopeKey('sms:verify:network:'.$networkKey),
                $scopeKey('sms:verify:network-hour:23:'.$networkKey),
                $scopeKey('verify:phone-before-midnight'),
            ])
            ->pluck('sends', 'key');
        $newDayRows = DB::table('customer_message_budgets')
            ->where('date', $nextDay->toDateString())
            ->whereIn('key', [
                $scopeKey('sms:global:day'),
                $scopeKey('sms:global:hour:00'),
                $scopeKey('sms:verify:day'),
                $scopeKey('sms:verify:hour:00'),
                $scopeKey('sms:verify:network:'.$networkKey),
                $scopeKey('sms:verify:network-hour:00:'.$networkKey),
                $scopeKey('verify:phone-after-midnight'),
            ])
            ->pluck('sends', 'key');

        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $oldDayRows->values()->map(fn ($sends) => (int) $sends)->all());
        $this->assertSame([1, 1, 1, 1, 1, 1, 1], $newDayRows->values()->map(fn ($sends) => (int) $sends)->all());
    }
}
