<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Notifications\CustomerPasswordReset;
use Illuminate\Support\Facades\Http;

class CustomerSmsChannel
{
    public function send(Customer $customer, CustomerPasswordReset $notification): void
    {
        $url = config('services.customer_sms.url');
        $token = config('services.customer_sms.token');
        if (! is_string($url) || ! str_starts_with($url, 'https://') || ! is_string($token) || $token === '') {
            throw new BusinessException('خدمة الرسائل النصية غير مهيأة حاليًا.', [], 503);
        }

        Http::withToken($token)->connectTimeout(3)->timeout(10)->post($url, [
            'to' => $customer->phone,
            'message' => $notification->toSms($customer),
        ])->throw();
    }
}
