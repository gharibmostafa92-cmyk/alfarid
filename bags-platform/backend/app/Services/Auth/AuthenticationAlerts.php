<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\StaffRole;
use App\Notifications\Auth\StaffAuthenticationAlert;
use App\Notifications\StaffRecipients;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

final class AuthenticationAlerts
{
    public function report(string $reason, ?int $staffId): void
    {
        logger()->warning('auth.failure_threshold', ['reason' => $reason, 'staff_id' => $staffId]);
        // M5: dedup identical alerts within a 15-minute window to survive credential-stuffing storms.
        if (! Cache::add('auth-alert:'.$reason.':'.($staffId ?? 'none'), 1, now()->addMinutes(15))) {
            return;
        }
        try {
            $recipients = StaffRecipients::withAnyPermission(['users.manage', 'users.update', 'roles.manage', 'settings.manage'])
                ->filter(fn ($user) => $user->hasRole(StaffRole::SuperAdmin->value, 'staff') || $user->can('inventory.all_branches'));
            Notification::sendNow($recipients, new StaffAuthenticationAlert($reason, $staffId));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
