<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Exceptions\BusinessException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
        if (! method_exists($notification, 'toSms')) {
            throw new BusinessException('الإشعار لا يدعم الإرسال عبر الرسائل النصية.', [], 500);
        }

        // Never place a network call with an empty recipient: a 400 from the
        // provider would otherwise surface as a 503 outage and page on-call.
        $to = $customer->routeNotificationFor('customer_sms', $notification) ?? $customer->phone;
        if (! is_string($to) || trim($to) === '') {
            throw new BusinessException('رقم هاتف المستلم غير متوفر؛ تعذر إرسال الرسالة النصية.', [], 422);
        }

        try {
            $response = Http::withToken($token)->connectTimeout(3)->timeout(10)->post($url, [
                'to' => $to,
                'message' => $notification->toSms($customer),
            ])->throw();
        } catch (ConnectionException) {
            throw new BusinessException('خدمة الرسائل النصية غير متاحة مؤقتًا؛ أعد المحاولة لاحقًا.', [], 503);
        } catch (RequestException $e) {
            // Input rejections (unknown sender, malformed number) are the
            // customer's data problem, not an outage: 4xx, not 503.
            $status = $e->response?->status() ?? 500;
            if ($status >= 400 && $status < 500) {
                throw new BusinessException('رفض مزود الرسائل النصية الرسالة؛ تحقق من رقم الهاتف ثم أعد المحاولة.', [], 422);
            }
            throw new BusinessException('خدمة الرسائل النصية غير متاحة مؤقتًا؛ أعد المحاولة لاحقًا.', [], 503);
        }
        if ($response->json('success') !== true) {
            throw new BusinessException('رفض مزود الرسائل إرسال الرسالة.', [], 502);
        }
    }
}
