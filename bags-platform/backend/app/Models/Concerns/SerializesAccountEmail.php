<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Customer;
use App\Rules\EmailNotUsedByOtherAccountType;
use Illuminate\Support\Facades\Validator;

trait SerializesAccountEmail
{
    public static function bootSerializesAccountEmail(): void
    {
        static::saving(function ($account): void {
            if (! $account->isDirty(['email', 'email_verified_at', 'deleted_at'])
                || ! $account->email
                || ($account instanceof Customer && ($account->email_verified_at === null || $account->trashed()))) {
                return;
            }

            $account->getConnection()->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                mb_strtolower(trim($account->email)),
            ]);
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
