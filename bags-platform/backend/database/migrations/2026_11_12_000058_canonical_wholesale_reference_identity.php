<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-10: the approved-notice duplicate guard normalized references with
 * lower(btrim()) only, so "REF-123", "REF 123" and Arabic-Indic digits
 * slipped through, and payment_method was part of the key — the same bank
 * transfer recorded once as bank_transfer and once as deposit credited
 * twice. Normalize aggressively (separators, Arabic-Indic digits, alef/hamza
 * and teh-marbuta/yeh variants) and key on (bank, reference) only.
 *
 * The bank identity function changes output for some inputs, so the old
 * index would go silently inconsistent: it is rebuilt, not patched.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION wholesale_reference_identity(reference text) RETURNS text
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
                SELECT nullif(regexp_replace(
                    translate(lower(btrim(reference)),
                        '٠١٢٣٤٥٦٧٨٩أإآةى',
                        '0123456789اااهي'),
                    '[[:space:].,_()/#\\-]+', '', 'g'), '')
            $$;
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
                FROM (SELECT regexp_replace(
                    translate(lower(btrim(bank_name)),
                        '٠١٢٣٤٥٦٧٨٩أإآةى',
                        '0123456789اااهي'),
                    '[[:space:].,_()/-]+', '', 'g') AS compact) normalized
            $$;
            SQL);

        // Duplicate references under the NEW identity (ignoring payment_method)
        // must be reconciled before the stricter unique index can be built.
        $dup = DB::selectOne(<<<'SQL'
            SELECT wholesale_bank_identity(bank_name) AS bank, wholesale_reference_identity(reference_number) AS ref
            FROM wholesale_payment_notices
            WHERE status = 'approved' AND wholesale_reference_identity(reference_number) IS NOT NULL
            GROUP BY 1, 2 HAVING COUNT(*) > 1 LIMIT 1
            SQL);
        if ($dup !== null) {
            throw new RuntimeException("Duplicate approved bank reference [{$dup->bank}/{$dup->ref}] under the canonical identity; reconcile before enabling the reference guard.");
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wholesale_notices_approved_canonical_reference_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY wholesale_notices_approved_reference_unique
            ON wholesale_payment_notices (wholesale_bank_identity(bank_name), wholesale_reference_identity(reference_number))
            WHERE status = 'approved'
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wholesale_notices_approved_reference_unique');
        DB::statement('DROP FUNCTION IF EXISTS wholesale_reference_identity(text)');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS wholesale_notices_approved_canonical_reference_unique
            ON wholesale_payment_notices (payment_method, wholesale_bank_identity(bank_name), lower(btrim(reference_number)))
            WHERE status = 'approved'
            SQL);
    }
};
