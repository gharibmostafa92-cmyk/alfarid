<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\CustomerSmsChannel;
use Illuminate\Notifications\Notification;

class CustomerPhoneVerificationCode extends Notification
{
    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return [CustomerSmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        return 'رمز التحقق من رقم الهاتف: '.$this->code;
    }
}
