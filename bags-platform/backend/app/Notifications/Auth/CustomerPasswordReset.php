<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

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

    /**
     * Reset link carries the raw token in the URL fragment and never in the
     * query string: fragments never leave the browser, so the secret stays
     * out of HTTP access logs, CDN logs, proxy logs, Referer headers, and
     * automatic link-preview fetches. The phone number is omitted entirely —
     * the token already identifies the reset server-side.
     * Kept in GSM-7 characters to fit a single 160-char SMS segment.
     */
    public function toSms(Customer $notifiable): string
    {
        return 'Password reset: '.$this->resetUrl();
    }

    private function resetUrl(): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return $base.'/auth/reset-password#token='.rawurlencode($this->token);
    }

    public function toMail(Customer $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('استعادة كلمة المرور')
            ->line('وصلنا طلب لاستعادة كلمة مرور حسابك.')
            ->action('استعادة كلمة المرور', $this->resetUrl())
            ->line('لو لم تطلب الاستعادة، تجاهل هذه الرسالة.');
    }
}
