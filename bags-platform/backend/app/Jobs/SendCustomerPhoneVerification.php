<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CustomerPhoneVerificationService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCustomerPhoneVerification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $phone, public readonly ?string $networkKey = null) {}

    public function handle(CustomerPhoneVerificationService $service): void
    {
        $service->send($this->phone, $this->networkKey);
    }
}
