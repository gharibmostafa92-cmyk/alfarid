<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Notifications\CustomerPhoneVerificationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Handles phone verification OTP delivery, verification, and proof token validation.
 */
class CustomerPhoneVerificationService
{
    /**
     * Generate and dispatch a 6-digit OTP code to the provided phone number.
     * Enforces a 60-second cooldown and daily message quota per phone key.
     */
    public function send(string $phone, ?string $networkKey = null): void
    {
        // Avoid sending verification codes to numbers already registered
        if (Customer::withTrashed()->where('phone', $phone)->exists()) {
            return;
        }
        $code = (string) random_int(100000, 999999);
        $key = hash('sha256', $phone);
        $created = DB::transaction(function () use ($key, $code, $networkKey): bool {
            DB::table('customer_phone_verifications')->insertOrIgnore([
                'phone_key' => $key, 'code_hash' => '', 'created_at' => now()->subDay(), 'expires_at' => now()->subDay(),
            ]);
            $row = DB::table('customer_phone_verifications')->where('phone_key', $key)->lockForUpdate()->first();
            if ($row->verified_at !== null && now()->lt($row->expires_at)) {
                return false;
            }
            if (now()->subSeconds(60)->lt($row->created_at) || ! app(CustomerMessageBudget::class)->claim('verify:'.$key, networkKey: $networkKey)) {
                return false;
            }
            DB::table('customer_phone_verifications')->where('phone_key', $key)->update([
                'code_hash' => 'hmac:'.hash_hmac('sha256', $key.':'.$code, (string) config('app.key')), 'proof_hash' => null, 'attempts' => 0, 'verified_at' => null,
                'created_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]);

            return true;
        });
        if ($created) {
            try {
                Notification::route('customer_sms', $phone)->notify(new CustomerPhoneVerificationCode($code));
                logger()->info('Customer OTP delivered.');
            } catch (\Throwable $exception) {
                logger()->error('Customer OTP delivery failed.', ['exception' => $exception::class]);
                throw $exception;
            }
        }
    }

    /**
     * Verify an OTP code and issue a one-time 64-character verification proof token.
     * Enforces a maximum of 5 attempts and a 10-minute expiration window.
     *
     * @throws BusinessException If code is invalid, expired, or maximum attempts exceeded
     */
    public function verify(string $phone, string $code): string
    {
        $proof = DB::transaction(function () use ($phone, $code): ?string {
            $key = hash('sha256', $phone);
            $row = DB::table('customer_phone_verifications')->where('phone_key', $key)->lockForUpdate()->first();
            if ($row === null || $row->attempts >= 5 || now()->gte($row->expires_at) || $row->verified_at !== null) {
                return null;
            }
            // Legacy bcrypt challenges remain valid during their ten-minute lifetime.
            $valid = str_starts_with($row->code_hash, 'hmac:')
                ? hash_equals($row->code_hash, 'hmac:'.hash_hmac('sha256', $key.':'.$code, (string) config('app.key')))
                : ($row->code_hash !== '' && Hash::check($code, $row->code_hash));
            DB::table('customer_phone_verifications')->where('phone_key', $key)->increment('attempts');
            if (! $valid) {
                return null;
            }
            $proof = Str::random(64);
            DB::table('customer_phone_verifications')->where('phone_key', $key)->update([
                'proof_hash' => hash('sha256', $proof), 'verified_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]);

            return $proof;
        });
        if ($proof === null) {
            throw new BusinessException('رمز التحقق غير صالح أو انتهت صلاحيته.');
        }

        return $proof;
    }

    /**
     * Validate the presence of a valid phone verification proof token on incoming request.
     *
     * @throws ValidationException
     */
    public function requireProof(Request $request): void
    {
        if (! config('auth.require_phone_verification')) {
            return;
        }
        $phone = $request->input('phone');
        $proof = $request->input('phone_verification_token');
        if (! is_string($phone) || ! is_string($proof) || strlen($proof) !== 64 || ! $this->validProof($phone, $proof)) {
            throw ValidationException::withMessages(['phone_verification_token' => 'تحقق من ملكية رقم الهاتف أولًا.']);
        }
    }

    /**
     * Verify phone ownership proof token against stored hash and check expiration.
     */
    private function validProof(string $phone, string $proof, bool $consume = false): bool
    {
        $query = DB::table('customer_phone_verifications')->where('phone_key', hash('sha256', $phone));
        $row = ($consume ? $query->lockForUpdate() : $query)->first();
        $stored = $row?->proof_hash ?? '';
        $matches = strlen($stored) === 64 ? hash_equals($stored, hash('sha256', $proof))
            : ($stored !== '' && Hash::check($proof, $stored));
        $valid = $matches && $row !== null && $row->verified_at !== null && now()->lt($row->expires_at);
        if ($valid && $consume) {
            $query->delete();
        }

        return $valid;
    }

    /**
     * Validate and atomically consume the phone verification token during registration.
     *
     * @throws BusinessException If token is missing, invalid, or expired
     */
    public function consume(array $data): bool
    {
        if (! config('auth.require_phone_verification')) {
            return false;
        }
        if (! $this->validProof($data['phone'], $data['phone_verification_token'] ?? '', true)) {
            throw new BusinessException('إثبات ملكية الهاتف غير صالح أو تم استخدامه.');
        }

        return true;
    }
}
