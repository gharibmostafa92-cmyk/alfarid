<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Return merchandise authorization (RMA) requests and item breakdowns.
 * Note: Request submission and initial approval do not adjust stock levels; inventory
 * movements occur strictly upon physical receipt and inspection via ReturnService::receive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('return_number')->unique();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Designated receiving warehouse or branch assigned during initial approval
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('refund_method', 30)->nullable();
            $table->decimal('total_refund_amount', 12, 2)->default(0);
            $table->text('reason');
            $table->text('admin_notes')->nullable();
            // External payment gateway refund ID or bank transfer receipt reference
            $table->string('refund_reference')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'received', 'refunded', 'cancelled'))");
        DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_refund_method_check CHECK (refund_method IS NULL OR refund_method IN ('original_payment', 'wallet', 'bank_transfer', 'cash'))");

        Schema::create('return_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_id')->constrained('return_requests')->cascadeOnDelete();
            // Source order line used to validate returnable quantity limits against prior returns
            $table->foreignId('order_item_id')->constrained('order_items');
            $table->foreignId('product_variant_id')->constrained('product_variants');
            $table->decimal('requested_qty', 12, 4);
            $table->decimal('approved_qty', 12, 4)->default(0);
            $table->decimal('received_good_qty', 12, 4)->default(0);
            $table->decimal('received_damaged_qty', 12, 4)->default(0);
            // Effective purchase price after apportioning item-level and order-level coupon discounts
            $table->decimal('unit_refund_price', 12, 4);
            $table->string('item_reason')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_request_items');
        Schema::dropIfExists('return_requests');
    }
};
