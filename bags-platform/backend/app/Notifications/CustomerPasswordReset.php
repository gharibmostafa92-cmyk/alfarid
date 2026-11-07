<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Customer;
use App\Notifications\Channels\CustomerSmsChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer password reset notification carrying the raw unhashed token.
 */
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

    /** Reset URL formatted in GSM-7 characters to fit single 160-char SMS segment. */
    public function toSms(Customer $notifiable): string
    {
        return 'Password reset: '.$this->resetUrl($notifiable);
    }

    private function resetUrl(Customer $notifiable): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return $base.'/reset-password?'.http_build_query(['token' => $this->token, 'phone' => $notifiable->phone]);
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
