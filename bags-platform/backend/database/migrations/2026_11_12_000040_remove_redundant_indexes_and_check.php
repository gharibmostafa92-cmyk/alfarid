<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Verify the covering indexes before removing the narrower copies.
        ConcurrentIndex::ensure('stock_movements_variant_id_id_index', 'ON stock_movements (product_variant_id, id DESC)');
        ConcurrentIndex::ensure('stock_movements_type_created_index', 'ON stock_movements (type, created_at)');
        ConcurrentIndex::ensure('pos_shifts_one_open_user', "ON pos_shifts (user_id) WHERE status = 'open'", unique: true);
        ConcurrentIndex::ensure('supplier_users_email_lower_unique', 'ON supplier_users (lower(btrim(email)))', unique: true);
        ConcurrentIndex::ensure('users_email_ci_unique', 'ON users (lower(email))', unique: true);

        foreach (['stock_movements_product_variant_id_index', 'stock_movements_type_index', 'pos_shifts_single_open_unique', 'supplier_users_email_ci_unique'] as $index) {
            ConcurrentIndex::drop($index);
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        });
        ConcurrentIndex::drop('users_email_unique');

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement('ALTER TABLE treasury_transfers DROP CONSTRAINT IF EXISTS treasury_transfers_separate_approver');
        });
    }

    public function down(): void
    {
        ConcurrentIndex::ensure('stock_movements_product_variant_id_index', 'ON stock_movements (product_variant_id)');
        ConcurrentIndex::ensure('stock_movements_type_index', 'ON stock_movements (type)');
        ConcurrentIndex::ensure('pos_shifts_single_open_unique', "ON pos_shifts (user_id, branch_id) WHERE status = 'open'", unique: true);
        ConcurrentIndex::ensure('supplier_users_email_ci_unique', 'ON supplier_users (lower(email))', unique: true);
        ConcurrentIndex::ensure('users_email_unique', 'ON users (email)', unique: true);

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            if (! DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'users'::regclass AND conname = 'users_email_unique')")) {
                DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE USING INDEX users_email_unique');
            }
        });
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement("ALTER TABLE treasury_transfers ADD CONSTRAINT treasury_transfers_separate_approver CHECK (status <> 'approved' OR approved_by <> created_by) NOT VALID");
        });
    }
};
