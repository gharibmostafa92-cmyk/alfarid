<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Auth\CustomerAuthService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued encrypted notification job dispatching customer password reset tokens via SMS or email.
 */
class SendCustomerPasswordReset implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Back off before retrying so a momentary provider outage does not
     * silently drop the reset without giving the customer another chance.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 45];
    }

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
