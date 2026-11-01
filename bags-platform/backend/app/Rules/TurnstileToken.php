<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

final class TurnstileToken implements ValidationRule
{
    /** يشتغل حتى لو الحقل مش مبعوت. */
    public bool $implicit = true;

    public function __construct(private readonly ?string $ip = null) {}

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
        try {
            $ok = Http::asForm()->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret, 'response' => $value, 'remoteip' => $this->ip,
                ])->json('success') === true;
        } catch (\Throwable $e) {
            report($e);
            $ok = false;
        }
        if (! $ok) {
            $fail('فشل التحقق البشري، حاول مرة أخرى.');
        }
    }
}
