<?php

declare(strict_types=1);

use App\Models\SupplierUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Constrains supplier_users.supplier_id foreign key with RESTRICT on delete to prevent accidental cascades on active users.
 * Disallows silent deletion of suppliers with linked portal accounts and indexes the foreign key column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = 'supplier_users_supplier_id_foreign'"
        );
        if ($exists !== null) {
            Schema::table('supplier_users', function (Blueprint $table): void {
                $table->dropForeign(['supplier_id']);
            });
        }

        DB::statement('CREATE INDEX IF NOT EXISTS supplier_users_supplier_id_index ON supplier_users (supplier_id)');

        // Deactivate orphan supplier accounts and revoke their tokens prior to adding the foreign
        // key constraint, preventing migration failure due to missing referenced records (23503).
        $orphanIds = DB::table('supplier_users as su')
            ->leftJoin('suppliers as s', 's.id', '=', 'su.supplier_id')
            ->whereNotNull('su.supplier_id')
            ->whereNull('s.id')
            ->pluck('su.id');
        if ($orphanIds->isNotEmpty()) {
            DB::table('supplier_users')->whereIn('id', $orphanIds)->update(['is_active' => false, 'supplier_id' => null]);
            DB::table('personal_access_tokens')
                ->where('tokenable_type', (new SupplierUser)->getMorphClass())
                ->whereIn('tokenable_id', $orphanIds)
                ->delete();
        }

        Schema::table('supplier_users', function (Blueprint $table): void {
            $table->foreign('supplier_id', 'supplier_users_supplier_id_foreign')
                ->references('id')->on('suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_users', function (Blueprint $table): void {
            $table->dropForeign('supplier_users_supplier_id_foreign');
            $table->foreign('supplier_id', 'supplier_users_supplier_id_foreign')
                ->references('id')->on('suppliers')->nullOnDelete();
        });
        DB::statement('DROP INDEX IF EXISTS supplier_users_supplier_id_index');
    }
};
