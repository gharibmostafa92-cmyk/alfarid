<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-19: a negative amount with direction=debit is a hidden credit — the entry
 * point the database cannot see coming from any source, including AI. Clamp
 * the column to positive values and prove every reversal mirrors its
 * original (same customer, same amount, opposite direction), since a CHECK
 * cannot run subqueries and the application check alone races.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE bad_id bigint;
            BEGIN
                SELECT id INTO bad_id FROM wallet_adjustments
                WHERE amount IS NULL OR amount::text = 'NaN' OR amount <= 0 LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION 'Reconcile wallet adjustment % with non-positive amount before enabling the amount guard', bad_id;
                END IF;

                SELECT r.id INTO bad_id FROM wallet_adjustments r
                JOIN wallet_adjustments o ON o.id = r.reverses_id
                WHERE r.reverses_id IS NOT NULL AND (
                    r.customer_id IS DISTINCT FROM o.customer_id
                    OR r.amount IS DISTINCT FROM o.amount
                    OR r.direction = o.direction
                ) LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION 'Reconcile wallet reversal % that does not mirror its original before enabling the reversal guard', bad_id;
                END IF;
            END;
            $$;
            ALTER TABLE wallet_adjustments
                ADD CONSTRAINT wallet_adjustments_amount_positive CHECK (amount > 0 AND amount::text <> 'NaN');
            CREATE OR REPLACE FUNCTION validate_wallet_adjustment_reversal() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE original record;
            BEGIN
                SELECT customer_id, amount, direction INTO original
                FROM wallet_adjustments WHERE id = NEW.reverses_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Wallet reversal references missing adjustment %', NEW.reverses_id USING ERRCODE = '23503';
                END IF;
                IF original.customer_id IS DISTINCT FROM NEW.customer_id
                    OR original.amount IS DISTINCT FROM NEW.amount
                    OR original.direction = NEW.direction THEN
                    RAISE EXCEPTION 'Wallet reversal must mirror the original amount and customer with the opposite direction' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER wallet_adjustments_validate_reversal
                BEFORE INSERT ON wallet_adjustments
                FOR EACH ROW WHEN (NEW.reverses_id IS NOT NULL)
                EXECUTE FUNCTION validate_wallet_adjustment_reversal();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS wallet_adjustments_validate_reversal ON wallet_adjustments;
            DROP FUNCTION IF EXISTS validate_wallet_adjustment_reversal();
            ALTER TABLE wallet_adjustments DROP CONSTRAINT IF EXISTS wallet_adjustments_amount_positive;
            SQL);
    }
};
