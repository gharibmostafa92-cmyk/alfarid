<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C2: ربط طلبات مرتجع POS بالأصل + عدّاد الكمية المرتجعة على البنود.
     * - orders.return_of_order_id: طلب المرتجع يشير للطلب الأصلي (null لغير المرتجعات).
     * - order_items.returned_qty: الكمية المرتجعة من هذا البند (0 افتراضيًا).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'return_of_order_id')) {
                $table->foreignId('return_of_order_id')
                    ->nullable()
                    ->after('pos_shift_id')
                    ->constrained('orders')
                    ->nullOnDelete();
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'returned_qty')) {
                $table->decimal('returned_qty', 10, 4)->default(0)->after('qty');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_returned_qty_check');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_returned_qty_check CHECK (returned_qty <= qty AND returned_qty >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_returned_qty_check');
        }

        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'returned_qty')) {
                $table->dropColumn('returned_qty');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'return_of_order_id')) {
                $table->dropConstrainedForeignId('return_of_order_id');
            }
        });
    }
};
