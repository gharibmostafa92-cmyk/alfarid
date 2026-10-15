<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفاتيح عدم التكرار (Idempotency) لطلبات التسويات اليدوية: إعادة إرسال نفس
 * الطلب (retry شبكة/ضغط مزدوج) تعيد الرد المحفوظ بدل تنفيذ الحركات مرتين،
 * ونفس المفتاح بحمولة مختلفة يُرفض 409. الإدراج داخل transaction التسوية نفسها،
 * ففشل الطلب يلغي المفتاح معه وتظل إعادة المحاولة ممكنة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->char('request_hash', 64);
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_idempotency_keys');
    }
};
