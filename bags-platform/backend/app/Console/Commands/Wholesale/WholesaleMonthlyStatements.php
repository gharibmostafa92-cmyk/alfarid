<?php

declare(strict_types=1);

namespace App\Console\Commands\Wholesale;

use App\Models\Customer;
use App\Services\Wholesale\WholesaleOutbox;
use Illuminate\Console\Command;

/**
 * Enqueues automated monthly account statements for opted-in wholesale customers.
 */
class WholesaleMonthlyStatements extends Command
{
    protected $signature = 'wholesale:monthly-statements';

    protected $description = 'Queue the monthly statement for opted-in wholesale customers';

    public function handle(WholesaleOutbox $outbox): int
    {
        $month = now((string) config('app.business_timezone', 'Africa/Cairo'))->format('Y-m');
        Customer::query()
            ->commercial()
            ->where('status', 'approved')
            ->whereNotNull('whatsapp_opt_in_at')
            ->orderBy('id')
            ->each(function (Customer $customer) use ($outbox, $month): void {
                $outbox->queue(
                    "monthly-statement:{$customer->id}:{$month}",
                    'customer_whatsapp',
                    $customer->id,
                    'customer_statement',
                    ['month' => $month],
                    $outbox->nextContactAt($customer),
                    true,
                );
            });

        return self::SUCCESS;
    }
}
