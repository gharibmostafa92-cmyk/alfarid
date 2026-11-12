<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-order last editor, delivery closed-short status, and the missing ASN parent index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_orders', 'last_modified_by')) {
                $table->foreignId('last_modified_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_deliveries DROP CONSTRAINT IF EXISTS supplier_deliveries_status_check');
            DB::statement("ALTER TABLE supplier_deliveries ADD CONSTRAINT supplier_deliveries_status_check CHECK (status IN ('pending_receipt', 'received', 'rejected', 'closed_short'))");
            ConcurrentIndex::ensure('supplier_delivery_items_supplier_delivery_id_index', 'ON supplier_delivery_items (supplier_delivery_id)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            ConcurrentIndex::drop('supplier_delivery_items_supplier_delivery_id_index');
            DB::statement("UPDATE supplier_deliveries SET status = 'rejected' WHERE status = 'closed_short'");
            DB::statement('ALTER TABLE supplier_deliveries DROP CONSTRAINT IF EXISTS supplier_deliveries_status_check');
            DB::statement("ALTER TABLE supplier_deliveries ADD CONSTRAINT supplier_deliveries_status_check CHECK (status IN ('pending_receipt', 'received', 'rejected'))");
        }

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_orders', 'last_modified_by')) {
                $table->dropConstrainedForeignId('last_modified_by');
            }
        });
    }
};
