<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEmail;

use App\Models\Customer;
use App\Services\CustomerEmail\CustomerEmailOutbox;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

class DeliverCustomerEmailsTest extends TestCase
{
    use RunsConcurrentAuditJobs;

    private const PROVIDER_ID = 'b8d8d48f-22ea-4924-a7e0-2937ee9b8332';

    private const FROM = 'Alfarid <orders@alfarid.example>';

    private int $recipientCounter = 0;

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schemaReady) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            self::$schemaReady = true;
        }

        DB::table('customer_email_outbox')->delete();
        DB::table('customers')->delete();
        Cache::store('database')->flush();

        config([
            'services.resend.key' => 're_test_fake_token',
            'services.resend.from' => self::FROM,
            'services.resend.environment' => 'testing',
            'services.resend.capture_enabled' => true,
            'services.resend.delivery_enabled' => true,
            'services.resend.minimum_interval_seconds' => 0,
            'services.resend.schedule_enabled' => true,
        ]);
        Cache::store('array')->flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('customer_email_outbox')->delete();
        DB::table('customers')->delete();
        Cache::store('database')->flush();
        Cache::store('array')->flush();

        parent::tearDown();
    }

    public function test_successful_delivery_clears_the_encrypted_payload_and_stores_provider_acceptance(): void
    {
        $this->queuedEmail();
        Http::fake(fn () => Http::response(['id' => self::PROVIDER_ID], 200));

        $exitCode = Artisan::call('customer-email:deliver');

        $row = DB::table('customer_email_outbox')->first();
        $this->assertSame(0, $exitCode);
        $this->assertSame('accepted', $row->status);
        $this->assertSame(self::PROVIDER_ID, $row->provider_id);
        $this->assertNull($row->payload);
        $this->assertNull($row->lease_token);
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->first_attempt_at);
        $this->assertNotNull(Cache::get('customer-email:worker-heartbeat-at'));
        Http::assertSentCount(1);
    }

    public function test_unreadable_encrypted_payload_moves_to_review_without_a_provider_call(): void
    {
        $this->queuedEmail();
        DB::table('customer_email_outbox')->update(['payload' => 'not-an-encrypted-payload']);
        Http::fake();

        $exitCode = Artisan::call('customer-email:deliver');

        $row = DB::table('customer_email_outbox')->first();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('review', $row->status);
        $this->assertSame('stored_payload_unreadable', $row->error_code);
        $this->assertNotNull($row->payload);
        Http::assertNothingSent();
    }

    public function test_outbox_rejects_payloads_larger_than_thirty_two_kibibytes(): void
    {
        $row = $this->queuedEmail();

        $this->expectException(QueryException::class);
        DB::table('customer_email_outbox')
            ->where('id', $row->id)
            ->update(['payload' => str_repeat('x', 32769)]);
    }

    public function test_transient_failure_retries_same_key_and_payload_before_eventual_acceptance(): void
    {
        Log::spy();
        $this->queuedEmail();
        Http::fakeSequence()
            ->push(['name' => 'application_error', 'message' => 'private provider error'], 503)
            ->push(['id' => self::PROVIDER_ID], 200);

        $this->assertSame(0, Artisan::call('customer-email:deliver'), Artisan::output());
        $pending = DB::table('customer_email_outbox')->first();
        $this->assertSame('pending', $pending->status);
        $this->assertSame(1, $pending->attempts);
        $this->assertNotNull($pending->payload);
        $this->assertSame('upstream_unavailable', $pending->error_code);
        Log::shouldHaveReceived('info')->with('Customer email delivery batch completed', \Mockery::on(
            fn (array $metrics): bool => $metrics['retry'] === 1 && $metrics['accepted'] === 0,
        ))->once();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame(0, Artisan::call('customer-email:deliver'));

        $accepted = DB::table('customer_email_outbox')->first();
        $this->assertSame('accepted', $accepted->status);
        $this->assertSame(2, $accepted->attempts);
        Log::shouldHaveReceived('info')->with('Customer email delivery batch completed', \Mockery::on(
            fn (array $metrics): bool => $metrics['retry'] === 0 && $metrics['accepted'] === 1,
        ))->once();
        $requests = Http::recorded()->map(fn (array $record): Request => $record[0]);
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]->body(), $requests[1]->body());
        $this->assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
    }

    public function test_rate_limit_sets_a_shared_cooldown_and_defers_the_message(): void
    {
        $this->queuedEmail();
        Http::fake(fn () => Http::response(['name' => 'rate_limit_exceeded'], 429, ['Retry-After' => '90']));
        $startedAt = now();

        $exitCode = Artisan::call('customer-email:deliver');
        $this->assertSame(0, $exitCode, Artisan::output());

        $row = DB::table('customer_email_outbox')->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame('rate_limited', $row->error_code);
        $firstAttemptAt = Carbon::parse($row->first_attempt_at);
        $availableAt = Carbon::parse($row->available_at);
        $this->assertGreaterThanOrEqual(90, $firstAttemptAt->diffInSeconds($availableAt));
        $this->assertGreaterThanOrEqual($startedAt->valueOf() + 90000, Cache::get('customer-email:cooldown-until'));
    }

    public function test_rate_limit_keeps_shared_cooldown_when_the_row_reaches_its_attempt_limit(): void
    {
        Log::spy();
        $first = $this->queuedEmail();
        $this->queuedEmail();
        DB::table('customer_email_outbox')->where('id', $first->id)->update([
            'attempts' => 7,
            'first_attempt_at' => now()->subHour(),
            'available_at' => now()->subSecond(),
        ]);
        Http::fakeSequence()
            ->push(['name' => 'rate_limit_exceeded'], 429, ['Retry-After' => '90'])
            ->push(['id' => self::PROVIDER_ID], 200);
        $startedAt = now();

        $this->assertSame(0, Artisan::call('customer-email:deliver'), Artisan::output());

        $rows = DB::table('customer_email_outbox')->orderBy('id')->get();
        $this->assertSame('review', $rows[0]->status);
        $this->assertSame('pending', $rows[1]->status);
        $this->assertSame(0, $rows[1]->attempts);
        $this->assertGreaterThanOrEqual($startedAt->valueOf() + 90000, Cache::get('customer-email:cooldown-until'));
        Http::assertSentCount(1);
        Log::shouldHaveReceived('info')->with('Customer email delivery batch completed', \Mockery::on(
            fn (array $metrics): bool => $metrics['review'] === 1 && $metrics['retry'] === 0,
        ))->once();
    }

    public function test_malformed_retry_after_uses_a_bounded_shared_cooldown_after_review(): void
    {
        $this->queuedEmail();
        $this->queuedEmail();
        Http::fakeSequence()
            ->push(['name' => 'rate_limit_exceeded'], 429, ['Retry-After' => 'not-a-duration'])
            ->push(['id' => self::PROVIDER_ID], 200);
        $startedAt = now();

        $this->assertSame(0, Artisan::call('customer-email:deliver'), Artisan::output());

        $rows = DB::table('customer_email_outbox')->orderBy('id')->get();
        $this->assertSame('review', $rows[0]->status);
        $this->assertSame('invalid_retry_after', $rows[0]->error_code);
        $this->assertSame('pending', $rows[1]->status);
        $this->assertGreaterThanOrEqual($startedAt->valueOf() + 60000, Cache::get('customer-email:cooldown-until'));
        Http::assertSentCount(1);
    }

    public function test_invalid_resend_credentials_pause_without_discarding_or_sending_later_rows(): void
    {
        $first = $this->queuedEmail();
        $this->queuedEmail();
        Http::fake(fn () => Http::response([
            'name' => 'validation_error',
            'message' => 'API key is invalid',
        ], 400));

        $this->assertSame(0, Artisan::call('customer-email:deliver'), Artisan::output());

        $rows = DB::table('customer_email_outbox')->orderBy('id')->get();
        $this->assertSame($first->id, $rows[0]->id);
        $this->assertSame('pending', $rows[0]->status);
        $this->assertSame('resend_credentials_rejected', $rows[0]->error_code);
        $this->assertSame(1, $rows[0]->attempts);
        $this->assertNotNull($rows[0]->payload);
        $this->assertSame('pending', $rows[1]->status);
        $this->assertSame(0, $rows[1]->attempts);
        $this->assertNotNull($rows[1]->payload);
        Http::assertSentCount(1);
    }

    public function test_pause_that_moves_a_row_to_review_is_counted_as_review(): void
    {
        Log::spy();
        $this->queuedEmail();
        DB::table('customer_email_outbox')->update([
            'attempts' => 1,
            'first_attempt_at' => now()->subHour(),
            'available_at' => now()->subSecond(),
        ]);
        Http::fake(fn () => Http::response(['name' => 'monthly_quota_exceeded'], 429));

        $this->assertSame(0, Artisan::call('customer-email:deliver'), Artisan::output());

        $this->assertSame('review', DB::table('customer_email_outbox')->value('status'));
        Log::shouldHaveReceived('info')->with('Customer email delivery batch completed', \Mockery::on(
            fn (array $metrics): bool => $metrics['review'] === 1 && $metrics['pause'] === 0,
        ))->once();
    }

    public function test_daily_quota_pauses_globally_until_utc_day_reset(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00', 'UTC'));
        $this->queuedEmail();
        Http::fake(fn () => Http::response(['name' => 'daily_quota_exceeded'], 429));

        $exitCode = Artisan::call('customer-email:deliver');
        $this->assertSame(0, $exitCode, Artisan::output());

        $expected = Carbon::parse('2026-11-19 00:00:00', 'UTC');
        $this->assertSame('pending', DB::table('customer_email_outbox')->value('status'));
        $this->assertSame('quota_daily_exceeded', DB::table('customer_email_outbox')->value('error_code'));
        $this->assertGreaterThanOrEqual($expected->valueOf(), Cache::get('customer-email:cooldown-until'));
    }

    public function test_message_past_twenty_three_hour_retry_window_moves_to_review_without_another_request(): void
    {
        $this->queuedEmail();
        DB::table('customer_email_outbox')->update([
            'first_attempt_at' => now()->subHours(23)->subSecond(),
            'attempts' => 2,
            'available_at' => now()->subSecond(),
        ]);
        Http::fake();

        Artisan::call('customer-email:deliver');

        $row = DB::table('customer_email_outbox')->first();
        $this->assertSame('review', $row->status);
        $this->assertSame('retry_window_exhausted', $row->error_code);
        $this->assertNotNull($row->payload);
        $this->assertNull($row->lease_token);
        Http::assertNothingSent();
    }

    public function test_eighth_attempt_moves_to_review_without_another_request(): void
    {
        $this->queuedEmail();
        DB::table('customer_email_outbox')->update([
            'first_attempt_at' => now()->subHour(),
            'attempts' => 8,
            'available_at' => now()->subSecond(),
        ]);
        Http::fake();

        Artisan::call('customer-email:deliver');

        $row = DB::table('customer_email_outbox')->first();
        $this->assertSame('review', $row->status);
        $this->assertSame('attempt_limit_reached', $row->error_code);
        $this->assertSame(8, $row->attempts);
        Http::assertNothingSent();
    }

    public function test_stale_sending_lease_can_be_reclaimed_and_completed(): void
    {
        $this->queuedEmail();
        DB::table('customer_email_outbox')->update([
            'status' => 'sending',
            'lease_token' => 'be033ba1-e12f-45d2-9dcf-d95d97a09554',
            'first_attempt_at' => now()->subHour(),
            'attempts' => 1,
            'available_at' => now()->subSecond(),
        ]);
        Http::fake(fn () => Http::response(['id' => self::PROVIDER_ID], 200));

        Artisan::call('customer-email:deliver');

        $row = DB::table('customer_email_outbox')->first();
        $this->assertSame('accepted', $row->status);
        $this->assertSame(2, $row->attempts);
        $this->assertSame(self::PROVIDER_ID, $row->provider_id);
    }

    public function test_old_worker_result_is_ignored_after_its_lease_is_replaced(): void
    {
        $this->queuedEmail();
        Http::fake(function () {
            DB::table('customer_email_outbox')->update([
                'lease_token' => 'aee33ba1-efa4-4df0-8b45-83fb2aa7c83a',
            ]);

            return Http::response(['id' => self::PROVIDER_ID], 200);
        });

        Artisan::call('customer-email:deliver');

        $row = DB::table('customer_email_outbox')->first();
        $this->assertSame('sending', $row->status);
        $this->assertSame('aee33ba1-efa4-4df0-8b45-83fb2aa7c83a', $row->lease_token);
        $this->assertNull($row->provider_id);
        $this->assertNotNull($row->payload);
    }

    public function test_accepted_resend_response_is_retried_with_identical_key_and_body_if_result_write_fails(): void
    {
        $this->queuedEmail();
        Http::fakeSequence()
            ->push(['id' => self::PROVIDER_ID], 200)
            ->push(['id' => self::PROVIDER_ID], 200);
        $failAcceptanceWrite = true;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$failAcceptanceWrite): void {
            if ($failAcceptanceWrite && in_array('accepted', $bindings, true)) {
                $failAcceptanceWrite = false;
                throw new \RuntimeException('sensitive address and token must not escape');
            }
        });

        $this->assertSame(1, Artisan::call('customer-email:deliver'));
        $this->assertStringNotContainsString('sensitive address and token', Artisan::output());
        $stale = DB::table('customer_email_outbox')->first();
        $this->assertSame('sending', $stale->status);
        $this->assertNotNull($stale->payload);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->assertSame(0, Artisan::call('customer-email:deliver'));
        $this->assertSame('accepted', DB::table('customer_email_outbox')->value('status'));

        $requests = Http::recorded()->map(fn (array $record): Request => $record[0]);
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]->body(), $requests[1]->body());
        $this->assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
    }

    public function test_new_message_delayed_before_its_first_attempt_is_still_eligible(): void
    {
        $this->queuedEmail();
        DB::table('customer_email_outbox')->update(['available_at' => now()->subDay()]);
        Http::fake(fn () => Http::response(['id' => self::PROVIDER_ID], 200));

        Artisan::call('customer-email:deliver');

        $row = DB::table('customer_email_outbox')->first();
        $this->assertSame('accepted', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->first_attempt_at);
    }

    public function test_delivery_batch_processes_at_most_fifty_messages(): void
    {
        $now = now();
        $payload = Crypt::encryptString(json_encode([
            'from' => self::FROM,
            'to' => ['customer@example.test'],
            'subject' => 'مرحبًا بك في الفريد',
            'text' => 'تم إنشاء حسابك بنجاح.',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $rows = [];
        for ($i = 1; $i <= 51; $i++) {
            $rows[] = [
                'event_key' => 'customer-email:testing:welcome/batch-'.$i,
                'payload' => $payload,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('customer_email_outbox')->insert($rows);
        Http::fake(fn () => Http::response(['id' => self::PROVIDER_ID], 200));

        Artisan::call('customer-email:deliver');

        $this->assertSame(50, DB::table('customer_email_outbox')->where('status', 'accepted')->count());
        $this->assertSame(1, DB::table('customer_email_outbox')->where('status', 'pending')->count());
        Http::assertSentCount(50);
    }

    public function test_concurrent_workers_claim_a_due_email_once_using_shared_cache_and_postgres(): void
    {
        $this->queuedEmail();
        Cache::store('database')->flush();

        $job = '$ready(); $exit = Illuminate\\Support\\Facades\\Artisan::call("customer-email:deliver"); return [$exit, Illuminate\\Support\\Facades\\Artisan::output()];';
        $results = $this->concurrentJobs([$job, $job], [
            'CACHE_STORE' => 'database',
            'CUSTOMER_EMAIL_DELIVERY_ENABLED' => 'true',
            'CUSTOMER_EMAIL_RATE_INTERVAL_SECONDS' => '0',
            'RESEND_API_KEY' => '',
            'RESEND_FROM_ADDRESS' => self::FROM,
            'RESEND_ENVIRONMENT' => 'testing',
        ]);

        $this->assertSame([[0, ''], [0, '']], $results);
        $row = DB::table('customer_email_outbox')->first();
        $this->assertSame(1, $row->attempts);
        $this->assertSame('pending', $row->status);
        $this->assertSame('resend_not_configured', $row->error_code);
    }

    public function test_review_cleanup_clears_only_old_content_and_caps_each_run_at_one_thousand(): void
    {
        $now = now();
        $payload = Crypt::encryptString('sensitive customer message');
        $rows = [];
        for ($i = 1; $i <= 1001; $i++) {
            $rows[] = [
                'event_key' => 'customer-email:testing:review/'.$i,
                'payload' => $payload,
                'status' => 'review',
                'attempts' => 1,
                'available_at' => $now->copy()->subDays(9),
                'created_at' => $now->copy()->subDays(9),
                'updated_at' => $now->copy()->subDays(8),
            ];
        }
        $rows[] = [
            'event_key' => 'customer-email:testing:review/new',
            'payload' => $payload,
            'status' => 'review',
            'attempts' => 1,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('customer_email_outbox')->insert($rows);

        $this->assertSame(0, Artisan::call('customer-email:prune-review'));

        $this->assertSame(1000, DB::table('customer_email_outbox')->where('status', 'review')->whereNull('payload')->count());
        $this->assertSame(2, DB::table('customer_email_outbox')->where('status', 'review')->whereNotNull('payload')->count());
    }

    public function test_scheduler_lists_email_delivery_and_cleanup_commands(): void
    {
        Artisan::call('schedule:list');

        $output = Artisan::output();
        $this->assertStringContainsString('customer-email:deliver', $output);
        $this->assertStringContainsString('customer-email:prune-review', $output);
    }

    public function test_due_query_uses_partial_index_when_outbox_is_mostly_final(): void
    {
        $now = now();
        $finalRows = [];
        for ($i = 1; $i <= 3000; $i++) {
            $finalRows[] = [
                'event_key' => 'customer-email:testing:accepted/'.$i,
                'payload' => null,
                'status' => 'accepted',
                'attempts' => 1,
                'available_at' => $now->copy()->subDays(10),
                'first_attempt_at' => $now->copy()->subDays(10),
                'lease_token' => null,
                'provider_id' => self::PROVIDER_ID,
                'error_code' => null,
                'created_at' => $now->copy()->subDays(10),
                'updated_at' => $now->copy()->subDays(10),
            ];
        }
        DB::table('customer_email_outbox')->insert($finalRows);
        $pendingRows = [];
        for ($i = 1; $i <= 50; $i++) {
            $pendingRows[] = [
                'event_key' => 'customer-email:testing:pending/future-'.$i,
                'payload' => Crypt::encryptString('{}'),
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $now->copy()->addDays($i),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $pendingRows[] = [
            'event_key' => 'customer-email:testing:pending/due',
            'payload' => Crypt::encryptString('{}'),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $now->copy()->subSecond(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('customer_email_outbox')->insert($pendingRows);
        DB::statement('ANALYZE customer_email_outbox');

        $plan = collect(DB::select("EXPLAIN (ANALYZE, BUFFERS) SELECT id FROM customer_email_outbox WHERE status IN ('pending', 'sending') AND available_at <= CURRENT_TIMESTAMP ORDER BY available_at, id LIMIT 1"))
            ->map(fn (object $line): string => (string) array_values((array) $line)[0])
            ->implode("\n");

        $this->assertStringContainsString('customer_email_outbox_due_idx', $plan);
    }

    private function queuedEmail(): object
    {
        $this->recipientCounter++;
        $customer = Customer::factory()->create([
            'email' => 'customer'.$this->recipientCounter.'@example.test',
        ]);
        app(CustomerEmailOutbox::class)->welcome($customer);

        return DB::table('customer_email_outbox')->firstOrFail();
    }
}
