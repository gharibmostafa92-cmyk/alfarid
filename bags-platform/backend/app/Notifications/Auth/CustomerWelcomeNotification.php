<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email delivered to newly registered customers using the varya HTML template.
 */
class CustomerWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(Customer $notifiable): array
    {
        return filled($notifiable->email) ? ['mail'] : [];
    }

    public function toMail(Customer $notifiable): MailMessage
    {
        $storefrontUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return (new MailMessage)
            ->subject('varya — Elevate Every Journey')
            ->view('emails.customer.welcome', [
                'customer' => $notifiable,
                'storefrontUrl' => $storefrontUrl,
            ]);
    }
}
