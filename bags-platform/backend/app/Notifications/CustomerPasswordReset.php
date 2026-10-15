<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Customer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** إشعار استعادة كلمة مرور العميل — يحمل الرمز الخام (المخزن في القاعدة مُجزّأ). */
class CustomerPasswordReset extends Notification
{
    public function __construct(private readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(Customer $notifiable): array
    {
        return ['mail'];
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
