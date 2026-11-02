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
        // M8: الخطة في transaction قصيرة (الجدول المؤقت ON COMMIT DROP على نفس الاتصال)
        // وتتحمّل في الذاكرة؛ بعدين كل batch في transaction مستقلة فالأقفال تتساب دفعة بدفعة.
        $plan = DB::transaction(fn (): ?array => $this->buildPlan());
        if ($plan === null) {
            return self::FAILURE;
        }
        $this->info(count($plan).' phone numbers need normalization.');
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        $done = 0;
        foreach (array_chunk($plan, 500) as $batch) {
            DB::transaction(function () use ($batch): void {
                foreach ($batch as $change) {
                    $customer = Customer::withTrashed()->lockForUpdate()->findOrFail($change->id);
                    if ($customer->phone !== $change->before) {
                        throw new \RuntimeException('A customer changed during normalization; earlier batches are committed — rerun the command to continue.');
                    }
                    $customer->phone = $change->after;
                    $customer->save();
                    CustomerAuditLog::record($customer, 'phone_normalized', null);
                }
            });
            $done += count($batch);
            $this->line("Normalized {$done}/".count($plan));
        }

        return self::SUCCESS;
    }

    /** @return list<object>|null */
    private function buildPlan(): ?array
    {
        DB::statement("CREATE TEMP TABLE customer_phone_normalization ON COMMIT DROP AS
            WITH cleaned AS (
                SELECT id, phone AS before,
                    regexp_replace(TRIM(phone), '^[[:space:]\u{00A0}\u{1680}\u{2000}-\u{200A}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}]+|[[:space:]\u{00A0}\u{1680}\u{2000}-\u{200A}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}]+$', '', 'g') AS trimmed,
                    regexp_replace(translate(TRIM(phone),
                        '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', '01234567890123456789'), '[[:space:]\u{00A0}\u{1680}\u{2000}-\u{200A}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}().-]+', '', 'g') AS value
                FROM customers
            )
            SELECT id, before, CASE
                WHEN trimmed ~ '^legacy-[0-9]+$' THEN trimmed
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

                return null;
            }

            return (clone $plan)->whereColumn('before', '!=', 'after')->orderBy('id')->get(['id', 'before', 'after'])->all();
        } finally {
            DB::statement('DROP TABLE IF EXISTS customer_phone_normalization');
        }
    }
}
