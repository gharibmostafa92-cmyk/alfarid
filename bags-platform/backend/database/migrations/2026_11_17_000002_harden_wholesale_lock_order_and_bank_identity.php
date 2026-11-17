<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-04 & F-09:
 * 1. Enforce deterministic `ORDER BY n.id FOR NO KEY UPDATE` in `lock_wholesale_allocation_parents()`
 *    when reopening a cancelled order to prevent heap-scan lock-order deadlocks.
 * 2. Extend `wholesale_reference_identity` and `wholesale_bank_identity` with Persian/Urdu digits,
 *    alef-wasla, and missing Arabic bank aliases, then rebuild the unique index concurrently.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION lock_wholesale_allocation_parents() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_TABLE_NAME = 'wholesale_payment_allocations' THEN
                        UPDATE orders SET updated_at = updated_at WHERE id = NEW.order_id;
                        UPDATE wholesale_payment_notices SET updated_at = updated_at WHERE id = NEW.notice_id;
                    ELSIF TG_TABLE_NAME = 'wholesale_payment_notices' THEN
                        IF NEW.status = 'approved' AND NEW.order_id IS NOT NULL THEN
                            UPDATE orders SET updated_at = updated_at WHERE id = NEW.order_id;
                        END IF;
                    ELSIF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
                        -- Lock notices in deterministic id order before updating to avoid deadlocks.
                        PERFORM 1 FROM wholesale_payment_notices n
                        WHERE n.order_id = NEW.id OR EXISTS (
                            SELECT 1 FROM wholesale_payment_allocations a WHERE a.order_id = NEW.id AND a.notice_id = n.id
                        )
                        ORDER BY n.id
                        FOR NO KEY UPDATE;

                        UPDATE wholesale_payment_notices n SET updated_at = n.updated_at
                        WHERE n.order_id = NEW.id OR EXISTS (
                            SELECT 1 FROM wholesale_payment_allocations a WHERE a.order_id = NEW.id AND a.notice_id = n.id
                        );
                    END IF;
                    RETURN NULL;
                END;
                $$;
            SQL);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION wholesale_reference_identity(reference text) RETURNS text
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
                SELECT nullif(regexp_replace(
                    translate(lower(btrim(reference)),
                        '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹أإآٱةى',
                        '01234567890123456789ااااهي'),
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
                    WHEN 'بنكقطرالوطني' THEN 'qnb'
                    WHEN 'بنكقطرالوطنيالاهلي' THEN 'qnb'
                    WHEN 'aaib' THEN 'aaib'
                    WHEN 'arabafricaninternationalbank' THEN 'aaib'
                    WHEN 'البنكالعربيالافريقي' THEN 'aaib'
                    WHEN 'البنكالعربيالافريقيالدولي' THEN 'aaib'
                    WHEN 'hsbc' THEN 'hsbc'
                    WHEN 'hsbcbankegypt' THEN 'hsbc'
                    WHEN 'bdc' THEN 'bdc'
                    WHEN 'banqueducaire' THEN 'bdc'
                    WHEN 'بنكالقاهرة' THEN 'bdc'
                    WHEN 'alexbank' THEN 'alexbank'
                    WHEN 'bankofalexandria' THEN 'alexbank'
                    WHEN 'بنكالاسكندريه' THEN 'alexbank'
                    ELSE compact
                END
                FROM (SELECT regexp_replace(
                    translate(lower(btrim(bank_name)),
                        '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹أإآٱةى',
                        '01234567890123456789ااااهي'),
                    '[[:space:].,_()/-]+', '', 'g') AS compact) normalized
            $$;
        SQL);

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS wholesale_notices_approved_reference_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY wholesale_notices_approved_reference_unique
            ON wholesale_payment_notices (
                wholesale_bank_identity(bank_name),
                wholesale_reference_identity(reference_number)
            )
            WHERE status = 'approved' AND wholesale_reference_identity(reference_number) IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Non-destructive: keeps functions valid.
    }
};
