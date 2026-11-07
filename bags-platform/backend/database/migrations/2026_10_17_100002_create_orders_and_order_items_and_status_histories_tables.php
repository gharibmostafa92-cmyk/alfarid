<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders, order items, and status transition audit histories.
 * Financial formula: grand_total = (subtotal - discount_amount) + shipping_cost + tax_amount.
 * Foreign key for coupon_id is deferred until the coupons table is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('channel')->default('store');
            // Nullable to support guest checkout
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('guest_phone', 50)->nullable();
            $table->string('guest_email')->nullable();
            // Intentionally unconstrained: preserves city historical reference even if cities are modified
            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->string('shipping_address_line')->nullable();
            $table->string('shipping_notes')->nullable();
            // Primary fulfillment branch when order is entirely fulfilled from one location
            $table->foreignId('allocated_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('coupon_id')->nullable()->index();
            $table->string('coupon_code')->nullable();
            $table->decimal('shipping_cost', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('channel');
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_channel_check CHECK (channel IN ('store', 'wholesale', 'pos', 'admin'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('cod', 'card', 'wallet', 'bank_transfer', 'credit'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('unpaid', 'paid', 'failed', 'refunded'))");

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot');
            // Line-level fulfillment branch to support split fulfillment across branches
            $table->foreignId('allocated_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('qty', 12, 4);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->nullableMorphs('changed_by');
            $table->string('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
