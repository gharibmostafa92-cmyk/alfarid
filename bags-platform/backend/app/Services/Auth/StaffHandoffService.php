<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issues and consumes one-time handoff codes for seamless staff app authentication transfers.
 */
final class StaffHandoffService
{
    private const TTL_SECONDS = 60;

    /**
     * Issue a single-use exchange code bound to the user's current credential fingerprint.
     */
    public function issue(User $user): string
    {
        $code = Str::random(64);
        Cache::put($this->key($code), ['user_id' => $user->id, 'credentials' => self::credentialFingerprint($user)], now()->addSeconds(self::TTL_SECONDS));

        return $code;
    }

    /**
     * Generate an HMAC fingerprint over user ID, email, and password hash to invalidate codes upon credential changes.
     */
    public static function credentialFingerprint(User $user): string
    {
        return hash_hmac('sha256', json_encode([$user->id, $user->email, $user->password], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * Atomically consume a handoff code using a mutex lock to prevent concurrent double-consumption.
     *
     * @return array{user_id:int, credentials:string}|null
     */
    public function consume(string $code): ?array
    {
        // Cache::pull alone is read-then-delete; serialize consumers so concurrent exchanges cannot issue two tokens.
        // On lock contention timeout, report the exception and treat code as consumed (null) instead of 500.
        try {
            return Cache::lock($this->key($code).':lock', 10)
                ->block(5, fn (): ?array => Cache::pull($this->key($code)));
        } catch (LockTimeoutException $exception) {
            report($exception);

            return null;
        }
    }

    private function key(string $code): string
    {
        return 'auth:handoff:'.hash('sha256', $code);
    }
}
