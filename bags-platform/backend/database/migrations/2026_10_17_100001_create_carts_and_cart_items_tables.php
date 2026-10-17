<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سلة التسوق: تعمل للزائر (cart_token) وللعميل المسجل (customer_id) معًا.
 * ملحوظة: coupon_id بلا قيد FK حاليًا — جدول الكوبونات لم يُبنَ بعد
 * (سيُضاف القيد مع مرحلة الكوبونات، مثلما حدث مع products.supplier_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('cart_token')->nullable()->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('coupon_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained();
            $table->decimal('qty', 12, 4);
            $table->timestamps();

            $table->unique(['cart_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
