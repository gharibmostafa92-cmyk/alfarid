<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Exceptions\BusinessException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class CustomerSmsChannel
{
    public function send(object $customer, Notification $notification): void
    {
        $url = config('services.customer_sms.url');
        $token = config('services.customer_sms.token');
        if (! is_string($url) || ! str_starts_with($url, 'https://') || ! is_string($token) || $token === '') {
            throw new BusinessException('خدمة الرسائل النصية غير مهيأة حاليًا.', [], 503);
        }

        $response = Http::withToken($token)->connectTimeout(3)->timeout(10)->post($url, [
            'to' => $customer->routeNotificationFor('customer_sms', $notification) ?? $customer->phone,
            'message' => $notification->toSms($customer),
        ])->throw();
        if ($response->json('success') !== true) {
            throw new BusinessException('رفض مزود الرسائل إرسال الرسالة.', [], 503);
        }
    }
}
