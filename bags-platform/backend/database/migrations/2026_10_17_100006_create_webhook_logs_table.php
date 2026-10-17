<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل طلبات الويب هوك الواردة — يُكتب قبل أي معالجة، وهو أساس
 * عدم تكرار المعالجة (Idempotency) عبر (provider, event_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider'); // payment:paymob | shipping:bosta ...
            $table->string('event_id')->nullable();
            $table->json('payload')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('status')->default('received'); // received|processed|duplicate|failed|invalid_signature|ignored
            $table->text('error')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // ملاحظة: لا قيد فريد — سطر السجل نفسه جزء من الأثر، والتكرار يُكتشف ويُعلَّم duplicate في الخدمة
            $table->index(['provider', 'event_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
