<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies Cloudflare Turnstile CAPTCHA response tokens against Cloudflare Siteverify API.
 */
final class TurnstileToken implements ValidationRule
{
    /** Evaluates rule even if token field is omitted from payload. */
    public bool $implicit = true;

    public function __construct(private readonly ?string $ip = null) {}

    /**
     * Validates the Turnstile challenge response token via Cloudflare Siteverify API.
     *
     * @param  string  $attribute  Validated attribute name
     * @param  mixed  $value  Submitted Turnstile token string
     * @param  Closure  $fail  Validation failure callback
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secret = (string) config('services.turnstile.secret_key');
        if ($secret === '') {
            if (! app()->environment(['local', 'testing'])) {
                $fail('التحقق البشري غير مهيأ.');
            }

            return;
        }
        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            $fail('أكمل التحقق البشري.');

            return;
        }
        $consumedKey = 'turnstile:consumed:'.hash('sha256', $value);
        if (Cache::has($consumedKey)) {
            $fail('فشل التحقق البشري، حاول مرة أخرى.');

            return;
        }
        try {
            $response = Http::asForm()->connectTimeout(1)->timeout(2)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret, 'response' => $value, 'remoteip' => $this->ip,
                ]);
            $expectedHost = (string) config('services.turnstile.expected_hostname', '');
            $hostname = (string) $response->json('hostname');
            $ok = $response->json('success') === true
                && ($expectedHost === '' || hash_equals($expectedHost, $hostname));
        } catch (\Throwable $e) {
            report($e);
            $ok = false;
        }
        if (! $ok) {
            Cache::put($consumedKey, 1, now()->addSeconds(30));
            $fail('فشل التحقق البشري، حاول مرة أخرى.');
        }
    }
}
