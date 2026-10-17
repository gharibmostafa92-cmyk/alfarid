<?php

namespace App\Jobs;

use App\Services\CustomerAuthService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCustomerPasswordReset implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly ?string $email, public readonly ?string $phone) {}

    /**
     * Execute the job.
     */
    public function handle(CustomerAuthService $auth): void
    {
        $auth->sendPasswordReset($this->email, $this->phone);
    }
}
