<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $index = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass('wallet_adjustments_one_reversal_idx')");
        if (! $index?->indisvalid) {
            if ($index !== null) {
                DB::statement('DROP INDEX CONCURRENTLY wallet_adjustments_one_reversal_idx');
            }

            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY wallet_adjustments_one_reversal_idx ON wallet_adjustments (reverses_id) WHERE reverses_id IS NOT NULL');
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE TRIGGER wallet_adjustments_immutable
                    BEFORE UPDATE OR DELETE ON wallet_adjustments
                    FOR EACH ROW EXECUTE FUNCTION prevent_wallet_mutation();
                CREATE OR REPLACE TRIGGER wallet_adjustments_no_truncate
                    BEFORE TRUNCATE ON wallet_adjustments
                    FOR EACH STATEMENT EXECUTE FUNCTION prevent_wallet_mutation();
                SQL);
        });
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS wallet_adjustments_immutable ON wallet_adjustments;
            DROP TRIGGER IF EXISTS wallet_adjustments_no_truncate ON wallet_adjustments;
            SQL);
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wallet_adjustments_one_reversal_idx');
    }
};
