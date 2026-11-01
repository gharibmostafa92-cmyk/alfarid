<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // M-5: CONCURRENTLY مينفعش جوه transaction.
    public $withinTransaction = false;

    private const STATUSES = "'pending', 'confirmed', 'executed', 'failed', 'cancelled', 'expired', 'undone'";

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            // H3
            DB::statement('ALTER TABLE ai_pending_actions DROP CONSTRAINT IF EXISTS ai_pending_actions_status_check');
            DB::statement('ALTER TABLE ai_pending_actions ADD CONSTRAINT ai_pending_actions_status_check CHECK (status IN ('.self::STATUSES.", 'bulk_child'))");
            // M12
            DB::statement('ALTER TABLE customers ADD COLUMN IF NOT EXISTS pre_suspension_status VARCHAR(20) NULL');
        });

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->index('customers_company_name_trgm_index', 'customers USING GIN (company_name gin_trgm_ops)');
        $this->index('customers_tax_number_trim_index', 'customers ((TRIM(tax_number)))');
        $this->index('customers_commercial_register_trim_index', 'customers ((TRIM(commercial_register)))');
        $this->index('customer_audit_logs_prunable_index', "customer_audit_logs (created_at, id) WHERE action IN ('login_failed', 'login_succeeded', 'token_issued')");
        $this->index('customers_phone_pattern_index', 'customers (phone text_pattern_ops)');
    }

    public function down(): void
    {
        $c = $this->concurrently();
        foreach (['customers_phone_pattern_index', 'customer_audit_logs_prunable_index', 'customers_commercial_register_trim_index', 'customers_tax_number_trim_index', 'customers_company_name_trgm_index'] as $name) {
            DB::statement("DROP INDEX {$c}IF EXISTS {$name}");
        }
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE customers DROP COLUMN IF EXISTS pre_suspension_status');
            DB::statement("UPDATE ai_pending_actions SET status = 'cancelled' WHERE status = 'bulk_child'");
            DB::statement('ALTER TABLE ai_pending_actions DROP CONSTRAINT IF EXISTS ai_pending_actions_status_check');
            DB::statement('ALTER TABLE ai_pending_actions ADD CONSTRAINT ai_pending_actions_status_check CHECK (status IN ('.self::STATUSES.'))');
        });
    }

    /** الاختبارات اللي بتلف الـ migrations في transaction تاخد الشكل العادي. */
    private function concurrently(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }

    private function index(string $name, string $definition): void
    {
        $c = $this->concurrently();
        // بناء CONCURRENTLY فاشل بيسيب فهرس INVALID وIF NOT EXISTS هيتخطاه.
        $invalid = DB::selectOne('SELECT 1 AS x FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = ? AND NOT i.indisvalid', [$name]);
        if ($invalid) {
            DB::statement("DROP INDEX {$c}IF EXISTS {$name}");
        }
        DB::statement("CREATE INDEX {$c}IF NOT EXISTS {$name} ON {$definition}");
    }
};
