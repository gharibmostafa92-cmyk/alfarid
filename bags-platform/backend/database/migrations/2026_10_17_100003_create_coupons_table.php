<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * كوبونات الخصم — أساس مبسط تستند إليه carts.coupon_id وorders.coupon_id
 * (ربط الكوبون بالسلة والتحقق منه في CouponService؛ إدارة الكوبونات CRUD لاحقًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('type')->default('percentage'); // percentage | fixed
            $table->decimal('value', 12, 2);
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_check CHECK (type IN ('percentage', 'fixed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
