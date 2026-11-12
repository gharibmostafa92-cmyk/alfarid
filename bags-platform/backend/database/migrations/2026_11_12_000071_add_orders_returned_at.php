<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F7: manual returns were dated by orders.updated_at, so any later touch moved
 * the negative revenue to another period. returned_at is written once on the
 * transition and is the only timestamp reports may use.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'returned_at')) {
                $table->timestamp('returned_at')->nullable()->after('status');
            }
        });

        // Best available value for historical rows: the last time the order changed.
        DB::table('orders')
            ->where('status', 'returned')
            ->whereNull('returned_at')
            ->update(['returned_at' => DB::raw('updated_at')]);

        ConcurrentIndex::ensure('orders_status_returned_at_index', 'ON orders (status, returned_at)');
    }

    public function down(): void
    {
        ConcurrentIndex::drop('orders_status_returned_at_index');

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'returned_at')) {
                $table->dropColumn('returned_at');
            }
        });
    }
};
