<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION wholesale_bank_identity(bank_name text) RETURNS text
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
                SELECT CASE compact
                    WHEN 'cib' THEN 'cib'
                    WHEN 'commercialinternationalbank' THEN 'cib'
                    WHEN 'commercialinternationalbankegypt' THEN 'cib'
                    WHEN 'البنكالتجاريالدولي' THEN 'cib'
                    WHEN 'البنكالتجارىالدولى' THEN 'cib'
                    WHEN 'nbe' THEN 'nbe'
                    WHEN 'nationalbankofegypt' THEN 'nbe'
                    WHEN 'البنكالأهليالمصري' THEN 'nbe'
                    WHEN 'البنكالاهليالمصري' THEN 'nbe'
                    WHEN 'banquemisr' THEN 'banque_misr'
                    WHEN 'banquedumisr' THEN 'banque_misr'
                    WHEN 'بنكمصر' THEN 'banque_misr'
                    WHEN 'qnb' THEN 'qnb'
                    WHEN 'qnbalahli' THEN 'qnb'
                    WHEN 'aaib' THEN 'aaib'
                    WHEN 'arabafricaninternationalbank' THEN 'aaib'
                    WHEN 'hsbc' THEN 'hsbc'
                    WHEN 'hsbcbankegypt' THEN 'hsbc'
                    WHEN 'bdc' THEN 'bdc'
                    WHEN 'banqueducaire' THEN 'bdc'
                    WHEN 'بنكالقاهرة' THEN 'bdc'
                    WHEN 'alexbank' THEN 'alexbank'
                    WHEN 'bankofalexandria' THEN 'alexbank'
                    ELSE compact
                END
                FROM (SELECT regexp_replace(lower(btrim(bank_name)), '[[:space:].,_()/-]+', '', 'g') AS compact) normalized
            $$;
            SQL);

        $index = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass('wholesale_notices_approved_canonical_reference_unique')");
        if ($index !== null && ! $index->indisvalid) {
            DB::statement('DROP INDEX CONCURRENTLY wholesale_notices_approved_canonical_reference_unique');
        }
        if (! $index?->indisvalid) {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX CONCURRENTLY wholesale_notices_approved_canonical_reference_unique
                ON wholesale_payment_notices (payment_method, wholesale_bank_identity(bank_name), lower(btrim(reference_number)))
                WHERE status = 'approved'
                SQL);
        }
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wholesale_notices_approved_bank_ref_unique');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS wholesale_notices_approved_bank_ref_unique
            ON wholesale_payment_notices (payment_method, lower(btrim(bank_name)), lower(btrim(reference_number)))
            WHERE status = 'approved'
            SQL);
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wholesale_notices_approved_canonical_reference_unique');
        DB::statement('DROP FUNCTION IF EXISTS wholesale_bank_identity(text)');
    }
};
