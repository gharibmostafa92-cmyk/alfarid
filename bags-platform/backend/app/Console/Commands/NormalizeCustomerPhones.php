<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeCustomerPhones extends Command
{
    protected $signature = 'customers:normalize-phones {--apply : Apply a collision-free normalization plan in batches}';

    protected $description = 'Check legacy Egyptian mobile numbers for normalization collisions before updating them';

    public function handle(): int
    {
        // Pin the temporary table to one connection under transaction pooling.
        return DB::transaction(fn (): int => $this->normalize());
    }

    private function normalize(): int
    {
        DB::statement("CREATE TEMP TABLE customer_phone_normalization ON COMMIT DROP AS
            WITH cleaned AS (
                SELECT id, phone AS before, regexp_replace(translate(TRIM(phone),
                    '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', '01234567890123456789'), '[[:space:]().-]+', '', 'g') AS value
                FROM customers
            )
            SELECT id, before, CASE
                WHEN before ~ '^legacy-[0-9]+$' THEN before
                WHEN value ~ '^201[0125][0-9]{8}$' THEN '0' || SUBSTRING(value FROM 3)
                WHEN value LIKE '+20%' THEN '0' || SUBSTRING(value FROM 4)
                WHEN value LIKE '0020%' THEN '0' || SUBSTRING(value FROM 5)
                ELSE value END AS after FROM cleaned");
        try {
            DB::statement('ALTER TABLE customer_phone_normalization ADD PRIMARY KEY (id)');
            $plan = DB::table('customer_phone_normalization');
            $invalid = (clone $plan)->whereRaw("after !~ '^(01[0125][0-9]{8}|legacy-[0-9]+)$'")->limit(20)->pluck('id')->all();
            $collisions = (clone $plan)->selectRaw('after')->groupBy('after')->havingRaw('COUNT(*) > 1')->limit(20)->pluck('after')->all();
            if ($invalid !== [] || $collisions !== []) {
                $this->error('Manual repair required. Invalid customer IDs (first 20): '.json_encode($invalid).'; normalized collisions: '.count($collisions));

                return self::FAILURE;
            }
            $changes = (clone $plan)->whereColumn('before', '!=', 'after');
            $this->info((clone $changes)->count().' phone numbers need normalization.');
            if ($this->option('apply')) {
                foreach ($changes->orderBy('id')->lazyById(500)->chunk(500) as $batch) {
                    DB::transaction(function () use ($batch): void {
                        foreach ($batch as $change) {
                            $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($change->id);
                            if ($customer->phone !== $change->before) {
                                throw new \RuntimeException('A customer changed during normalization; rerun the preflight.');
                            }
                            $customer->phone = $change->after;
                            $customer->save();
                            CustomerAuditLog::record($customer, 'phone_normalized', null);
                        }
                    });
                }
            }

            return self::SUCCESS;
        } finally {
            DB::statement('DROP TABLE IF EXISTS customer_phone_normalization');
        }
    }
}
