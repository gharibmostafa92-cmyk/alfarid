<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->bigInteger('version')->default(1);
        });
        Schema::table('stocktake_items', function (Blueprint $table): void {
            $table->bigInteger('snapshot_movement_id')->default(0);
        });
        Schema::table('inventory_idempotency_keys', function (Blueprint $table): void {
            $table->index('created_at');
        });

        // A session advisory lock lives only for this statement, never for the
        // inventory transaction. Both allocation and raising the floor use it.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_movement_number(minimum bigint DEFAULT NULL) RETURNS bigint
LANGUAGE plpgsql AS $$
DECLARE result bigint;
BEGIN
    PERFORM pg_advisory_lock(7042961051501);
    BEGIN
        IF minimum IS NULL THEN
            SELECT nextval('stock_movement_number_seq') INTO result;
        ELSE
            SELECT GREATEST(minimum, last_value + CASE WHEN is_called THEN 1 ELSE 0 END)
                INTO result FROM stock_movement_number_seq;
            PERFORM setval('stock_movement_number_seq', result, false);
        END IF;
    EXCEPTION WHEN query_canceled THEN
        PERFORM pg_advisory_unlock(7042961051501);
        RAISE;
    WHEN OTHERS THEN
        PERFORM pg_advisory_unlock(7042961051501);
        RAISE;
    END;
    PERFORM pg_advisory_unlock(7042961051501);
    RETURN result;
END;
$$;
SQL);

        foreach (['inventory.all_branches', 'inventory.view_cost'] as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission, 'guard_name' => 'staff',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS inventory_movement_number(bigint)');
        Schema::table('inventory_idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
        Schema::table('stocktake_items', function (Blueprint $table): void {
            $table->dropColumn('snapshot_movement_id');
        });
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
