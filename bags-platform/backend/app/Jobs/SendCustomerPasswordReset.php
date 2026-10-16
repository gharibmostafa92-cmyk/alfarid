<?php

namespace App\Jobs;

use App\Services\CustomerAuthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCustomerPasswordReset implements ShouldQueue
{
    use Queueable;

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
