<?php

declare(strict_types=1);

namespace App\Console\Commands\CustomerEmail;

use App\Services\CustomerEmail\ResendResult;
use App\Services\CustomerEmail\ResendSender;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class DeliverCustomerEmails extends Command
{
    protected $signature = 'customer-email:deliver';

    protected $description = 'Deliver queued customer emails through Resend';

    private const MAX_MESSAGES_PER_RUN = 50;

    private const MAX_RUNTIME_SECONDS = 40;

    private const REQUEST_TIMEOUT_SECONDS = 8;

    private const LEASE_SECONDS = 60;

    private const RETRY_WINDOW_HOURS = 23;

    private const CACHE_COOLDOWN_KEY = 'customer-email:cooldown-until';

    private const CACHE_NEXT_SEND_KEY = 'customer-email:next-send-at';

    private const CACHE_GATE_LOCK = 'customer-email:shared-gate-lock';

    private const CACHE_GATE_LOCK_SECONDS = 10;

    /** @var array{accepted: int, retry: int, permanent: int, pause: int, review: int} */
    private array $counts = [
        'accepted' => 0,
        'retry' => 0,
        'permanent' => 0,
        'pause' => 0,
        'review' => 0,
    ];

    public function handle(ResendSender $sender): int
    {
        $this->counts = ['accepted' => 0, 'retry' => 0, 'permanent' => 0, 'pause' => 0, 'review' => 0];
        $started = microtime(true);
        $attempted = 0;
        $maxLatencyMs = 0;
        $this->heartbeat();

        if (! config('services.resend.delivery_enabled', false)) {
            return self::SUCCESS;
        }

        try {
            while ($attempted < self::MAX_MESSAGES_PER_RUN) {
                if ($this->remainingSeconds($started) <= self::REQUEST_TIMEOUT_SECONDS) {
                    break;
                }

                $waitMilliseconds = $this->reserveSendSlot();

                if ($waitMilliseconds > 0) {
                    $remainingMilliseconds = (int) (($this->remainingSeconds($started) - self::REQUEST_TIMEOUT_SECONDS) * 1000);

                    if ($waitMilliseconds >= $remainingMilliseconds) {
                        break;
                    }

                    usleep(min($waitMilliseconds, 1000) * 1000);

                    continue;
                }

                $claim = $this->claimOne();

                if ($claim === null) {
                    break;
                }

                if (is_array($claim) && isset($claim['reviewed'])) {
                    $this->counts['review']++;
                    $this->alertReview((int) $claim['reviewed']);

                    continue;
                }

                $attempted++;
                $startedRequest = microtime(true);
                if (! is_string($claim->payload) || strlen($claim->payload) > 32768) {
                    $result = new ResendResult('review', safeCode: 'stored_payload_invalid');
                } else {
                    try {
                        $jsonBody = Crypt::decryptString($claim->payload);
                        $result = $sender->send($claim->event_key, $jsonBody);
                    } catch (DecryptException) {
                        $result = new ResendResult('review', safeCode: 'stored_payload_unreadable');
                    }
                }
                $maxLatencyMs = max($maxLatencyMs, (int) round((microtime(true) - $startedRequest) * 1000));

                if (! $this->persistResult($claim, $result)) {
                    continue;
                }

                $this->counts[$result->kind]++;
            }
        } catch (Throwable $exception) {
            Log::error('Customer email delivery batch aborted', [
                'code' => 'internal_error',
                'exception_class' => class_basename($exception),
            ]);
            $this->heartbeat();
            $this->error('Customer email delivery aborted (internal_error).');

            return self::FAILURE;
        }

        $oldestDueSeconds = $this->oldestDueSeconds();
        $this->heartbeat();
        Log::info('Customer email delivery batch completed', [
            'attempted' => $attempted,
            'accepted' => $this->counts['accepted'],
            'retry' => $this->counts['retry'],
            'failed' => $this->counts['permanent'],
            'pause' => $this->counts['pause'],
            'review' => $this->counts['review'],
            'max_latency_ms' => $maxLatencyMs,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'oldest_due_seconds' => $oldestDueSeconds,
        ]);

        if ($oldestDueSeconds !== null && $oldestDueSeconds >= 600) {
            if (Cache::add('customer-email:backlog-alert', true, now()->addMinutes(10))) {
                Log::warning('Customer email outbox backlog exceeds ten minutes', [
                    'oldest_due_seconds' => $oldestDueSeconds,
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function reserveSendSlot(): int
    {
        return Cache::lock(self::CACHE_GATE_LOCK, self::CACHE_GATE_LOCK_SECONDS)->block(2, function (): int {
            $nowMilliseconds = (int) now()->valueOf();
            $cooldownMilliseconds = (int) Cache::get(self::CACHE_COOLDOWN_KEY, 0);
            $nextSendMilliseconds = (int) Cache::get(self::CACHE_NEXT_SEND_KEY, 0);
            $availableAtMilliseconds = max($cooldownMilliseconds, $nextSendMilliseconds);

            if ($availableAtMilliseconds > $nowMilliseconds) {
                return (int) ($availableAtMilliseconds - $nowMilliseconds);
            }

            $intervalSeconds = max(0, min(60, (int) config('services.resend.minimum_interval_seconds', 1)));

            if ($intervalSeconds > 0) {
                Cache::put(self::CACHE_NEXT_SEND_KEY, $nowMilliseconds + ($intervalSeconds * 1000), 300);
            }

            return 0;
        });
    }

    /**
     * Claims one row only. A review marker is returned after changing the row
     * in place so a large expired queue never loads payloads into memory.
     */
    private function claimOne(): object|array|null
    {
        return DB::transaction(function (): object|array|null {
            $now = now();
            $row = DB::table('customer_email_outbox')
                ->whereIn('status', ['pending', 'sending'])
                ->where('available_at', '<=', $now)
                ->orderBy('available_at')
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            if ($row === null) {
                return null;
            }

            $firstAttemptAt = $row->first_attempt_at === null
                ? null
                : CarbonImmutable::parse($row->first_attempt_at);
            $windowEndsAt = $firstAttemptAt?->addHours(self::RETRY_WINDOW_HOURS);
            $attemptLimitReached = (int) $row->attempts >= 8;

            if ($attemptLimitReached || ($windowEndsAt !== null && $windowEndsAt->lessThanOrEqualTo($now))) {
                DB::table('customer_email_outbox')
                    ->where('id', $row->id)
                    ->where('status', $row->status)
                    ->where('available_at', '<=', $now)
                    ->update([
                        'status' => 'review',
                        'lease_token' => null,
                        'error_code' => $attemptLimitReached ? 'attempt_limit_reached' : 'retry_window_exhausted',
                        'updated_at' => $now,
                    ]);

                return ['reviewed' => (int) $row->id];
            }

            $leaseToken = (string) Str::uuid();
            $attempts = (int) $row->attempts + 1;
            $updated = DB::table('customer_email_outbox')
                ->where('id', $row->id)
                ->where('status', $row->status)
                ->where('available_at', '<=', $now)
                ->update([
                    'status' => 'sending',
                    'attempts' => $attempts,
                    'first_attempt_at' => $row->first_attempt_at ?? $now,
                    'lease_token' => $leaseToken,
                    'available_at' => $now->copy()->addSeconds(self::LEASE_SECONDS),
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return null;
            }

            $row->status = 'sending';
            $row->attempts = $attempts;
            $row->first_attempt_at = $row->first_attempt_at ?? $now;
            $row->lease_token = $leaseToken;

            return $row;
        });
    }

    private function persistResult(object $claim, ResendResult $result): bool
    {
        $now = CarbonImmutable::instance(now());
        $changes = match ($result->kind) {
            'accepted' => [
                'status' => 'accepted',
                'payload' => null,
                'provider_id' => $result->providerId,
                'error_code' => null,
                'lease_token' => null,
                'updated_at' => $now,
            ],
            'permanent' => [
                'status' => 'failed',
                'payload' => null,
                'provider_id' => null,
                'error_code' => $result->safeCode,
                'lease_token' => null,
                'updated_at' => $now,
            ],
            'retry' => $this->retryChanges($claim, $result, $now),
            'pause' => $this->pauseChanges($claim, $result, $now),
            'review' => [
                'status' => 'review',
                'provider_id' => null,
                'error_code' => $result->safeCode,
                'lease_token' => null,
                'updated_at' => $now,
            ],
        };

        if (isset($changes['cooldown_until'])) {
            $cooldownUntil = (int) $changes['cooldown_until'];
            unset($changes['cooldown_until']);
            $this->extendCooldown($cooldownUntil);
        }

        $updated = DB::table('customer_email_outbox')
            ->where('id', $claim->id)
            ->where('status', 'sending')
            ->where('lease_token', $claim->lease_token)
            ->update($changes);

        if ($updated !== 1) {
            return false;
        }

        if (($changes['status'] ?? null) === 'review') {
            $this->alertReview((int) $claim->id);
        }

        if ($result->kind === 'pause') {
            $this->alertPause($result->safeCode ?? 'resend_paused', $now);
        }

        return true;
    }

    private function retryChanges(object $claim, ResendResult $result, CarbonImmutable $now): array
    {
        $baseDelay = min(3600, 30 * (2 ** max(0, (int) $claim->attempts - 1)));
        $jitterLimit = min(30, 3600 - $baseDelay);
        $delaySeconds = max($baseDelay + ($jitterLimit > 0 ? random_int(0, $jitterLimit) : 0), $result->retryAfterSeconds ?? 0);
        $availableAt = $now->addSeconds($delaySeconds);
        $review = $this->mustReviewForRetryWindow($claim, $availableAt);

        if ($review !== null) {
            return [
                'status' => 'review',
                'provider_id' => null,
                'error_code' => $review,
                'lease_token' => null,
                'updated_at' => $now,
            ];
        }

        $changes = [
            'status' => 'pending',
            'provider_id' => null,
            'error_code' => $result->safeCode,
            'lease_token' => null,
            'available_at' => $availableAt,
            'updated_at' => $now,
        ];

        if ($result->safeCode === 'rate_limited') {
            $changes['cooldown_until'] = max($availableAt->valueOf(), $now->addSeconds(max(1, $result->retryAfterSeconds ?? $delaySeconds))->valueOf());
        }

        return $changes;
    }

    private function pauseChanges(object $claim, ResendResult $result, CarbonImmutable $now): array
    {
        $pauseUntil = $this->pauseUntil($result->safeCode, $now);
        $review = $this->mustReviewForRetryWindow($claim, $pauseUntil);

        if ($review !== null) {
            return [
                'status' => 'review',
                'provider_id' => null,
                'error_code' => $review,
                'lease_token' => null,
                'updated_at' => $now,
                'cooldown_until' => $pauseUntil->valueOf(),
            ];
        }

        return [
            'status' => 'pending',
            'provider_id' => null,
            'error_code' => $result->safeCode,
            'lease_token' => null,
            'available_at' => $pauseUntil,
            'updated_at' => $now,
            'cooldown_until' => $pauseUntil->valueOf(),
        ];
    }

    private function mustReviewForRetryWindow(object $claim, CarbonImmutable $nextAttemptAt): ?string
    {
        if ((int) $claim->attempts >= 8) {
            return 'attempt_limit_reached';
        }

        $firstAttemptAt = CarbonImmutable::parse($claim->first_attempt_at);
        $windowEndsAt = $firstAttemptAt->addHours(self::RETRY_WINDOW_HOURS);

        return $nextAttemptAt->greaterThanOrEqualTo($windowEndsAt) ? 'retry_window_exhausted' : null;
    }

    private function pauseUntil(?string $safeCode, CarbonImmutable $now): CarbonImmutable
    {
        return match ($safeCode) {
            'quota_daily_exceeded' => $now->utc()->startOfDay()->addDay(),
            'quota_monthly_exceeded' => $now->utc()->startOfMonth()->addMonth(),
            default => $now->addMinutes(15),
        };
    }

    private function extendCooldown(int $untilMilliseconds): void
    {
        Cache::lock(self::CACHE_GATE_LOCK, self::CACHE_GATE_LOCK_SECONDS)->block(2, function () use ($untilMilliseconds): void {
            $current = (int) Cache::get(self::CACHE_COOLDOWN_KEY, 0);
            $untilMilliseconds = max($current, $untilMilliseconds);
            $ttlSeconds = max(60, (int) ceil(($untilMilliseconds - now()->valueOf()) / 1000) + 60);
            Cache::put(self::CACHE_COOLDOWN_KEY, $untilMilliseconds, $ttlSeconds);
        });
    }

    private function alertPause(string $safeCode, CarbonImmutable $now): void
    {
        [$period, $ttl] = match ($safeCode) {
            'quota_daily_exceeded' => ['day:'.$now->utc()->format('Y-m-d'), 86400],
            'quota_monthly_exceeded' => ['month:'.$now->utc()->format('Y-m'), 2678400],
            default => ['hour:'.$now->utc()->format('Y-m-d-H'), 3600],
        };

        if (Cache::add('customer-email:pause-alert:'.$period, true, $ttl)) {
            Log::error('Customer email delivery is paused', ['code' => $safeCode]);
        }
    }

    private function alertReview(int $id): void
    {
        if (Cache::add('customer-email:review-alert:'.$id, true, now()->addDays(7))) {
            Log::error('Customer email requires manual review', ['code' => 'delivery_ambiguous']);
        }
    }

    private function oldestDueSeconds(): ?int
    {
        $availableAt = DB::table('customer_email_outbox')
            ->whereIn('status', ['pending', 'sending'])
            ->where('available_at', '<=', now())
            ->min('available_at');

        if ($availableAt === null) {
            return null;
        }

        return max(0, (int) CarbonImmutable::parse($availableAt)->diffInSeconds(CarbonImmutable::now()));
    }

    private function heartbeat(): void
    {
        Cache::put('customer-email:worker-heartbeat-at', now()->toIso8601String(), 600);
    }

    private function remainingSeconds(float $started): float
    {
        return self::MAX_RUNTIME_SECONDS - (microtime(true) - $started);
    }
}
