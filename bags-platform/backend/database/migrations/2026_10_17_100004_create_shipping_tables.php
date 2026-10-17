<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الشحن: شركات ومناطق وأسعار وبوالص.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique(); // bosta | aramex | internal ...
            $table->text('api_credentials')->nullable(); // مشفرة عبر Cast في الموديل — text لأن النص المشفر ليس JSON
            $table->string('tracking_url_template')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_zones', function (Blueprint $table): void {
            $table->id();
            $table->string('name'); // القاهرة الكبرى | الدلتا | الصعيد ...
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_zone_cities', function (Blueprint $table): void {
            $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            // بلا FK على cities: تراجع هجرة المرحلة 5.1 يُسقط cities مباشرة (نفس سبب orders.city_id)
            $table->unsignedBigInteger('city_id')->index();

            $table->primary(['shipping_zone_id', 'city_id']);
        });

        Schema::create('shipping_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_cost', 10, 2);
            // شحن مجاني إذا تخطى الطلب هذا المبلغ
            $table->decimal('free_shipping_threshold', 12, 2)->nullable();
            // رسوم الدفع عند الاستلام
            $table->decimal('cod_fee', 10, 2)->default(0);
            $table->unsignedInteger('estimated_days_min');
            $table->unsignedInteger('estimated_days_max');
            $table->timestamps();

            $table->unique(['shipping_company_id', 'shipping_zone_id']);
        });

        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_company_id')->constrained();
            $table->string('awb_number')->unique();
            $table->string('status')->default('created');
            $table->string('label_url')->nullable();
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_status_check CHECK (status IN ('created', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_zone_cities');
        Schema::dropIfExists('shipping_zones');
        Schema::dropIfExists('shipping_companies');
    }
};
