<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Customer\CustomerType;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Support\AccountEmailLock;
use Illuminate\Support\Facades\Validator;

trait SerializesAccountEmail
{
    public static function bootSerializesAccountEmail(): void
    {
        static::saving(function ($account): void {
            if (! $account->isDirty(['email', 'email_verified_at', 'type', 'deleted_at'])
                || ! $account->email
                || ($account instanceof Customer && $account->trashed())) {
                return;
            }

            AccountEmailLock::acquire($account->email);
            Validator::make(['email' => $account->email], [
                'email' => [new EmailNotUsedByOtherAccountType($account->getTable())],
            ])->validate();

            if (! $account instanceof Customer) {
                $normalizedEmail = mb_strtolower(trim($account->email));
                Customer::query()
                    ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                    ->where('type', CustomerType::Retail->value)
                    ->whereNull('email_verified_at')
                    ->get()
                    ->each(function (Customer $customer): void {
                        $customer->forceFill(['email' => null])->saveQuietly();
                        CustomerAuditLog::record($customer, 'unverified_email_released', null, [
                            'reason' => 'claimed_by_other_realm',
                        ]);
                    });
            }
        });
    }

    public function save(array $options = []): bool
    {
        // Keep the advisory lock until the write commits, including standalone model saves.
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }
}
