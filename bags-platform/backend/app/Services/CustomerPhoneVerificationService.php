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

class CustomerPhoneVerificationService
{
    public function send(string $phone): void
    {
        if (Customer::withTrashed()->where('phone', $phone)->exists()) {
            return;
        }
        $code = (string) random_int(100000, 999999);
        $key = hash('sha256', $phone);
        $created = DB::transaction(function () use ($key, $code): bool {
            DB::table('customer_phone_verifications')->insertOrIgnore([
                'phone_key' => $key, 'code_hash' => '', 'created_at' => now()->subDay(), 'expires_at' => now()->subDay(),
            ]);
            $row = DB::table('customer_phone_verifications')->where('phone_key', $key)->lockForUpdate()->first();
            if ($row->verified_at !== null && now()->lt($row->expires_at)) {
                return false;
            }
            if (now()->subSeconds(60)->lt($row->created_at) || ! app(CustomerMessageBudget::class)->claim('verify:'.$key)) {
                return false;
            }
            DB::table('customer_phone_verifications')->where('phone_key', $key)->update([
                'code_hash' => Hash::make($code), 'proof_hash' => null, 'attempts' => 0, 'verified_at' => null,
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

    public function verify(string $phone, string $code): string
    {
        $proof = DB::transaction(function () use ($phone, $code): ?string {
            $key = hash('sha256', $phone);
            $row = DB::table('customer_phone_verifications')->where('phone_key', $key)->lockForUpdate()->first();
            $valid = Hash::check($code, $row?->code_hash ?: CustomerAuthService::dummyHash());
            if ($row === null || $row->attempts >= 5 || now()->gte($row->expires_at) || $row->verified_at !== null) {
                return null;
            }
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
