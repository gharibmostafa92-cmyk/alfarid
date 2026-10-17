<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 8.2: العروض التلقائية + ترقية الكوبونات وسجل استخداماتها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type'); // percentage_discount | fixed_discount | buy_x_get_y | free_shipping
            $table->decimal('value', 12, 2)->default(0);
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->string('applies_to')->default('all'); // all | categories | brands | variants
            $table->json('target_ids')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_type_check CHECK (type IN ('percentage_discount', 'fixed_discount', 'buy_x_get_y', 'free_shipping'))");
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_applies_to_check CHECK (applies_to IN ('all', 'categories', 'brands', 'variants'))");

        // ترقية الكوبونات: نوع شحن مجاني + حد استخدام لكل عميل
        Schema::table('coupons', function (Blueprint $table): void {
            $table->unsignedInteger('usage_limit_per_customer')->default(1);
        });
        DB::statement('ALTER TABLE coupons DROP CONSTRAINT IF EXISTS coupons_type_check');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_check CHECK (type IN ('percentage', 'fixed', 'free_shipping'))");

        Schema::create('coupon_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('discount_amount', 12, 2);
            $table->timestamp('created_at')->nullable();

            $table->index(['coupon_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
        DB::statement('ALTER TABLE coupons DROP CONSTRAINT IF EXISTS coupons_type_check');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_check CHECK (type IN ('percentage', 'fixed'))");
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropColumn('usage_limit_per_customer');
        });
        Schema::dropIfExists('promotions');
    }
};
