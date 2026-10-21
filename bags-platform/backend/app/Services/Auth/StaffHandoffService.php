<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class StaffHandoffService
{
    private const TTL_SECONDS = 60;

    public function issue(User $user): string
    {
        $code = Str::random(64);
        Cache::put($this->key($code), ['user_id' => $user->id], now()->addSeconds(self::TTL_SECONDS));

        return $code;
    }

    /** @return array{user_id:int}|null */
    public function consume(string $code): ?array
    {
        // Cache::pull alone is a read followed by delete on database/Redis stores.
        // Serialize consumers so concurrent exchanges cannot issue two tokens.
        // M8: block() يرمي LockTimeoutException لو تعذّر القفل (تزامن/بطء) —
        // تُعامل ككود مستهلك (null) في الكنترولر بدل 500.
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
