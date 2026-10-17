<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ValidateCustomerDomains extends Command
{
    protected $signature = 'customers:validate-domains';

    protected $description = 'Validate existing customer records against the new domain constraints without blocking normal writes';

    public function handle(): int
    {
        foreach (['customers_nonnegative_credit', 'customers_approved_business', 'customers_valid_phone'] as $constraint) {
            try {
                DB::statement('ALTER TABLE customers VALIDATE CONSTRAINT '.$constraint);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) !== '23514') {
                    throw $exception;
                }
                $this->error($constraint.': existing records need manual repair before validation.');

                return self::FAILURE;
            }
        }
        $this->info('Customer domain constraints validated.');

        return self::SUCCESS;
    }
}
