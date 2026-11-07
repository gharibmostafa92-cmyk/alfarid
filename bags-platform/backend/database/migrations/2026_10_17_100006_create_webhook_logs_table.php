<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound webhook audit log recorded prior to execution to enforce idempotent event processing.
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

            // Intentionally not unique: audit trail preserves every delivery attempt, with duplicate detection handled in service logic
            $table->index(['provider', 'event_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
