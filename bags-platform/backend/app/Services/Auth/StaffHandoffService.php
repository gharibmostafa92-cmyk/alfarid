<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
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
    public function issue(User $user, Request $request): string
    {
        $code = Str::random(64);
        Cache::put($this->key($code), [
            'user_id' => $user->id,
            'credentials' => self::credentialFingerprint($user),
            'browser' => $this->browserFingerprint($request),
        ], now()->addSeconds(self::TTL_SECONDS));

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
    public function consume(string $code, Request $request): ?array
    {
        // Cache::pull alone is read-then-delete; serialize consumers so concurrent exchanges cannot issue two tokens.
        // On lock contention timeout, report the exception and treat code as consumed (null) instead of 500.
        try {
            $key = $this->key($code);

            return Cache::lock($key.':lock', 10)->block(5, function () use ($key, $request): ?array {
                $payload = Cache::get($key);
                if (! is_string($payload['browser'] ?? null)
                    || ! hash_equals($payload['browser'], $this->browserFingerprint($request))) {
                    return null;
                }

                return Cache::forget($key) ? $payload : null;
            });
        } catch (LockTimeoutException $exception) {
            report($exception);

            return null;
        }
    }

    private function browserFingerprint(Request $request): string
    {
        return hash_hmac('sha256', NetworkIdentity::forRequest($request)."\0".$request->userAgent(), (string) config('app.key'));
    }

    private function key(string $code): string
    {
        return 'auth:handoff:'.hash('sha256', $code);
    }
}
