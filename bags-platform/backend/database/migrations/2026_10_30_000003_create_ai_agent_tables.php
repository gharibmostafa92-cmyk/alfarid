<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('title')->nullable();
            $t->string('model');
            $t->unsignedInteger('total_tokens')->default(0);
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['user_id', 'last_message_at']);
        });
        Schema::create('ai_messages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $t->enum('role', ['user', 'model', 'tool', 'event']);
            $t->text('content')->nullable();
            $t->jsonb('ui_blocks')->nullable();
            $t->jsonb('provider_parts')->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->timestamps();
            $t->index(['conversation_id', 'id']);
        });
        Schema::create('ai_pending_actions', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('tool');
            $t->jsonb('args');
            $t->jsonb('expected_versions');
            $t->jsonb('preview');
            $t->jsonb('inverse')->nullable();
            $t->string('args_hash', 64);
            $t->enum('risk', ['low', 'medium', 'high']);
            $t->enum('status', ['pending', 'confirmed', 'executed', 'failed', 'cancelled', 'expired', 'undone']);
            $t->jsonb('result')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('executed_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pending_actions');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
