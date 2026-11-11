<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEDGERS = [
        ['customers', 'wallet_balance', 'customer_wallet_transactions', 'customer_id', 'wallet'],
        ['treasuries', 'current_balance', 'treasury_transactions', 'treasury_id', 'treasury'],
    ];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '2s'");

        foreach (self::LEDGERS as [$parent, $balance, $ledger, $owner, $name]) {
            DB::unprepared(<<<SQL
                LOCK TABLE {$parent}, {$ledger} IN SHARE ROW EXCLUSIVE MODE;
                CREATE INDEX {$name}_ledger_owner_sequence_idx ON {$ledger} ({$owner}, id);

                DO \$\$
                DECLARE
                    invalid_id bigint;
                BEGIN
                    SELECT entry.id INTO invalid_id
                    FROM (
                        SELECT id, amount, balance_after,
                            lag(balance_after, 1, 0::numeric) OVER (PARTITION BY {$owner} ORDER BY id) AS previous_balance
                        FROM {$ledger}
                    ) entry
                    WHERE entry.amount::text = 'NaN' OR entry.balance_after::text = 'NaN'
                        OR entry.previous_balance::text = 'NaN'
                        OR entry.balance_after <> entry.previous_balance + entry.amount
                    LIMIT 1;
                    IF FOUND THEN
                        RAISE EXCEPTION 'Reconcile {$ledger} entry % before enabling balance enforcement', invalid_id;
                    END IF;

                    SELECT account.id INTO invalid_id
                    FROM {$parent} account
                    LEFT JOIN LATERAL (
                        SELECT balance_after FROM {$ledger}
                        WHERE {$owner} = account.id ORDER BY id DESC LIMIT 1
                    ) latest ON true
                    WHERE account.{$balance} IS DISTINCT FROM coalesce(latest.balance_after, 0)
                    LIMIT 1;
                    IF FOUND THEN
                        RAISE EXCEPTION 'Reconcile {$parent} account % before enabling balance enforcement', invalid_id;
                    END IF;
                END;
                \$\$;

                CREATE OR REPLACE FUNCTION lock_{$name}_ledger_owner() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    PERFORM 1 FROM {$parent} WHERE id = NEW.{$owner} FOR NO KEY UPDATE;
                    RETURN NEW;
                END;
                \$\$;

                CREATE OR REPLACE FUNCTION check_{$name}_ledger_balance() RETURNS trigger LANGUAGE plpgsql AS \$\$
                DECLARE
                    owner_id bigint;
                    stored_balance numeric;
                    latest_balance numeric;
                    previous_balance numeric;
                    next_balance numeric;
                    next_amount numeric;
                BEGIN
                    IF TG_TABLE_NAME = '{$ledger}' THEN
                        owner_id := NEW.{$owner};
                        -- The append-only guard protects existing rows. Checking both neighbors
                        -- also covers explicit IDs and concurrent inserts that reserve IDs early.
                        SELECT balance_after INTO previous_balance FROM {$ledger}
                        WHERE {$owner} = owner_id AND id < NEW.id ORDER BY id DESC LIMIT 1;

                        SELECT amount, balance_after INTO next_amount, next_balance FROM {$ledger}
                        WHERE {$owner} = owner_id AND id > NEW.id ORDER BY id LIMIT 1;

                        IF NEW.amount::text = 'NaN' OR NEW.balance_after::text = 'NaN'
                            OR previous_balance::text = 'NaN' OR next_balance::text = 'NaN' OR next_amount::text = 'NaN'
                            OR NEW.balance_after <> coalesce(previous_balance, 0) + NEW.amount
                            OR (next_balance IS NOT NULL AND next_balance <> NEW.balance_after + next_amount) THEN
                            RAISE check_violation USING CONSTRAINT = '{$name}_ledger_chain',
                                MESSAGE = format('Invalid {$name} ledger balance at entry %s', NEW.id);
                        END IF;
                    ELSE
                        owner_id := NEW.id;
                    END IF;

                    SELECT {$balance} INTO stored_balance FROM {$parent} WHERE id = owner_id;
                    IF NOT FOUND THEN
                        RETURN NULL;
                    END IF;

                    SELECT balance_after INTO latest_balance FROM {$ledger}
                    WHERE {$owner} = owner_id ORDER BY id DESC LIMIT 1;

                    IF stored_balance::text = 'NaN' OR latest_balance::text = 'NaN'
                        OR stored_balance IS DISTINCT FROM coalesce(latest_balance, 0) THEN
                        RAISE check_violation USING CONSTRAINT = '{$name}_ledger_current_balance',
                            MESSAGE = format('Stored {$name} balance differs from the ledger for account %s', owner_id);
                    END IF;
                    RETURN NULL;
                END;
                \$\$;

                CREATE TRIGGER {$name}_ledger_lock_owner BEFORE INSERT ON {$ledger}
                    FOR EACH ROW EXECUTE FUNCTION lock_{$name}_ledger_owner();
                CREATE CONSTRAINT TRIGGER {$name}_ledger_check_entry AFTER INSERT ON {$ledger}
                    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_{$name}_ledger_balance();
                CREATE CONSTRAINT TRIGGER {$name}_ledger_check_account AFTER INSERT OR UPDATE OF {$balance}, id ON {$parent}
                    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_{$name}_ledger_balance();
                SQL);
        }
    }

    public function down(): void
    {
        foreach (self::LEDGERS as [$parent, , $ledger, , $name]) {
            DB::unprepared(<<<SQL
                DROP TRIGGER {$name}_ledger_check_account ON {$parent};
                DROP TRIGGER {$name}_ledger_check_entry ON {$ledger};
                DROP TRIGGER {$name}_ledger_lock_owner ON {$ledger};
                DROP FUNCTION check_{$name}_ledger_balance();
                DROP FUNCTION lock_{$name}_ledger_owner();
                DROP INDEX {$name}_ledger_owner_sequence_idx;
                SQL);
        }
    }
};
