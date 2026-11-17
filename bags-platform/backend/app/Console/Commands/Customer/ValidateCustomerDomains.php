<?php

declare(strict_types=1);

namespace App\Console\Commands\Customer;

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
                // VALIDATE CONSTRAINT takes SHARE UPDATE EXCLUSIVE: fail fast
                // instead of freezing behind an unrelated long query.
                DB::transaction(function () use ($constraint): void {
                    if (DB::getDriverName() === 'pgsql') {
                        // Flush deferred guard triggers within this transaction only so PgBouncer
                        // pooled connections are never polluted outside transaction boundaries.
                        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                        DB::statement("SET LOCAL lock_timeout = '3s'");
                    }
                    DB::statement('ALTER TABLE customers VALIDATE CONSTRAINT '.$constraint);
                });
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
