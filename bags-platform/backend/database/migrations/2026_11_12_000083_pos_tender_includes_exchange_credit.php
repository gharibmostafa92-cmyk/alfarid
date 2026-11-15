<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * task6 backend lane (F3-netting): exchange orders settle part of the grand
 * total with return credit instead of cash/card, so the tender invariant must
 * count the credit: cash + card + exchange_credit == grand_total.
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
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_pos_tender_total');
            DB::statement(<<<'SQL'
                ALTER TABLE orders ADD CONSTRAINT orders_pos_tender_total CHECK (
                    channel <> 'pos'
                    OR (cash_amount IS NULL AND card_amount IS NULL)
                    OR (
                        COALESCE(cash_amount, 0) >= 0 AND COALESCE(card_amount, 0) >= 0
                        AND COALESCE(exchange_credit, 0) >= 0
                        AND COALESCE(cash_amount, 0) + COALESCE(card_amount, 0)
                            + COALESCE(exchange_credit, 0) = grand_total
                    )
                ) NOT VALID
                SQL);
        });

        $this->validateTenderConstraint();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_pos_tender_total');
            DB::statement(<<<'SQL'
                ALTER TABLE orders ADD CONSTRAINT orders_pos_tender_total CHECK (
                    channel <> 'pos'
                    OR (cash_amount IS NULL AND card_amount IS NULL)
                    OR (
                        COALESCE(cash_amount, 0) >= 0 AND COALESCE(card_amount, 0) >= 0
                        AND COALESCE(cash_amount, 0) + COALESCE(card_amount, 0) = grand_total
                    )
                ) NOT VALID
                SQL);
        });

        $hasExchangeCredits = (bool) DB::scalar("SELECT EXISTS(SELECT 1 FROM orders WHERE COALESCE(exchange_credit, 0) > 0)");
        if (! $hasExchangeCredits) {
            $this->validateTenderConstraint();
        }
    }

    private function validateTenderConstraint(): void
    {
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE orders VALIDATE CONSTRAINT orders_pos_tender_total');
        });
    }
};
