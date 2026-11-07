<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency keys for manual stock adjustments: network retries replay the saved response,
 * while conflicting payloads with the same key are rejected with 409 Conflict.
 * Keys are committed within the adjustment transaction to allow retries upon failure.
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
