<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\StaffAdministration;

final class StaffAuthenticationAudit
{
    public static function record(?User $user, string $result): void
    {
        if ($user === null) {
            return;
        }
        try {
            StaffAdministration::audit($result === 'success' ? $user : null,
                'authentication.'.$result, 'user', $user->id, []);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
