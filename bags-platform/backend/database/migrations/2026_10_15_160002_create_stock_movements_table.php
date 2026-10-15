<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            // الأعمدة التالية nullable/defaulted عشان عقد اختبارات الفروع/المواقع من المرحلة 1
            // (إدخال صف بحقول branch_id/location_id فقط). الاكتمال مضمون تطبيقيًا لأن
            // الكتابة الوحيدة للدفتر بتتم عبر InventoryService اللي بيملأ كل الحقول دائمًا.
            $table->string('reference_number')->nullable()->unique();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32)->nullable();
            $table->string('affected_bucket', 16)->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('balance_before', 12, 4)->default(0);
            $table->decimal('balance_after', 12, 4)->default(0);
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->nullableMorphs('reference');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('product_variant_id');
            $table->index('branch_id');
            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
