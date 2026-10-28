<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4: جدول تخصيص الإشعارات الحرة على الطلبات (FIFO) — حتى تُخصم
 * المبالغ الموزِّعة من الائتمان الحر مرة واحدة ولا تُحسب مرتين
 * (تسوية الطلب + بقاؤها دائنًا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notice_id')->constrained('wholesale_payment_notices')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['notice_id', 'order_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_payment_allocations');
    }
};
