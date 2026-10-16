<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Customer;
use App\Notifications\Channels\CustomerSmsChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** إشعار استعادة كلمة مرور العميل — يحمل الرمز الخام (المخزن في القاعدة مُجزّأ). */
class CustomerPasswordReset extends Notification
{
    public function __construct(private readonly string $token, private readonly ?string $channel = null) {}

    /**
     * @return list<string>
     */
    public function via(Customer $notifiable): array
    {
        return [$this->channel ?? ($notifiable->email ? 'mail' : CustomerSmsChannel::class)];
    }

    public function toSms(Customer $notifiable): string
    {
        return 'رمز استعادة كلمة المرور: '.$this->token;
    }

    public function toMail(Customer $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('استعادة كلمة المرور')
            ->line('وصلنا طلب لاستعادة كلمة مرور حسابك.')
            ->line('رمز الاستعادة: '.$this->token)
            ->line('لو لم تطلب الاستعادة، تجاهل هذه الرسالة.');
    }
}
