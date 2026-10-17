<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ‏needs_attention: علم مراقبة لطلبات تحتاج تدخلًا بشريًا (M19).
 * ‏cod_fee: رسوم الدفع عند الاستلام المحصّلة من العميل (M12).
 * ‏branches.city_id: ربط الفرع بمدينة بمعرّف بدل مطابقة الاسم النصي (L5)،
 * بلا FK تماشيًا مع نمط المشروع (تراجع هجرات أخرى يسقط جدول المدن).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('needs_attention')->default(false);
            $table->decimal('cod_fee', 12, 2)->default(0);
        });
        Schema::table('branches', function (Blueprint $table): void {
            $table->unsignedBigInteger('city_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['needs_attention', 'cod_fee']);
        });
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('city_id');
        });
    }
};
