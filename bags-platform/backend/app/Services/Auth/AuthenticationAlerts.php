<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\StaffRole;
use App\Notifications\StaffAuthenticationAlert;
use App\Notifications\StaffRecipients;
use Illuminate\Support\Facades\Notification;

final class AuthenticationAlerts
{
    public function report(string $reason, ?int $staffId): void
    {
        logger()->warning('auth.failure_threshold', ['reason' => $reason, 'staff_id' => $staffId]);
        try {
            $recipients = StaffRecipients::withAnyPermission(['users.manage', 'users.update', 'roles.manage', 'settings.manage'])
                ->filter(fn ($user) => $user->hasRole(StaffRole::SuperAdmin->value, 'staff') || $user->can('inventory.all_branches'));
            Notification::sendNow($recipients, new StaffAuthenticationAlert($reason, $staffId));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
