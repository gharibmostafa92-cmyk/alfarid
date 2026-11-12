<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paid-line snapshot for refunds, return audit columns, and non-negative refund checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'coupon_discount_amount')) {
                $table->decimal('coupon_discount_amount', 12, 2)->nullable();
            }
        });

        Schema::table('return_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('return_requests', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
            }
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE return_request_items DROP CONSTRAINT IF EXISTS return_request_items_refund_nonnegative');
        DB::statement('ALTER TABLE return_request_items ADD CONSTRAINT return_request_items_refund_nonnegative CHECK (refund_amount >= 0 AND unit_refund_price >= 0)');
        DB::statement('ALTER TABLE return_requests DROP CONSTRAINT IF EXISTS return_requests_total_refund_nonnegative');
        DB::statement('ALTER TABLE return_requests ADD CONSTRAINT return_requests_total_refund_nonnegative CHECK (total_refund_amount >= 0)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE return_request_items DROP CONSTRAINT IF EXISTS return_request_items_refund_nonnegative');
            DB::statement('ALTER TABLE return_requests DROP CONSTRAINT IF EXISTS return_requests_total_refund_nonnegative');
        }

        Schema::table('return_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('return_requests', 'rejected_by')) {
                $table->dropConstrainedForeignId('rejected_by');
                $table->dropColumn('rejected_at');
                $table->dropConstrainedForeignId('cancelled_by');
                $table->dropColumn('cancelled_at');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'coupon_discount_amount')) {
                $table->dropColumn('coupon_discount_amount');
            }
        });
    }
};
