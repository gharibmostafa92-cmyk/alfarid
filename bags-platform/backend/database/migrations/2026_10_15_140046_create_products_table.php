<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique();          // P00001 بيتولد تلقائي
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable()->index(); // الـ FK هيتضاف في مرحلة الموردين
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('slug')->unique();
            $table->longText('description_ar')->nullable();
            $table->longText('description_en')->nullable();
            $table->string('material_ar')->nullable();
            $table->string('material_en')->nullable();
            $table->decimal('length_cm', 8, 2)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->string('warranty_details')->nullable();
            $table->string('country_of_origin')->nullable();
            $table->string('status')->default('draft');            // ProductStatus
            $table->string('sales_channel')->default('both');      // SalesChannel
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'sales_channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
