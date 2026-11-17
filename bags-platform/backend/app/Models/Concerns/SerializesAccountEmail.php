<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Customer;
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

            // Cross-realm emails are unique even before verification: an
            // unverified retail address must still block staff/supplier reuse.
            AccountEmailLock::acquire($account->email);
            Validator::make(['email' => $account->email], [
                'email' => [new EmailNotUsedByOtherAccountType($account->getTable())],
            ])->validate();
        });
    }

    public function save(array $options = []): bool
    {
        // Keep the advisory lock until the write commits, including standalone model saves.
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }
}
