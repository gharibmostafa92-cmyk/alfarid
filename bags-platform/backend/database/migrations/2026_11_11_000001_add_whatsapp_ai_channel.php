<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->string('channel', 16)->default('web');
            $table->index(['user_id', 'channel', 'last_message_at']);
        });

        Schema::create('ai_whatsapp_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('phone_e164', 20)->unique();
            $table->string('wa_jid')->nullable()->unique();
            $table->string('wa_lid')->nullable()->unique();
            $table->enum('status', ['pending', 'active', 'revoked']);
            $table->timestamp('verified_at')->nullable();
            $table->string('pin_hash')->nullable();
            $table->timestamp('unlocked_until')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('ai_whatsapp_link_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('identity_id')->constrained('ai_whatsapp_identities')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_whatsapp_inbound', function (Blueprint $table): void {
            $table->id();
            $table->string('wa_message_id')->unique();
            $table->foreignId('identity_id')->nullable()->constrained('ai_whatsapp_identities')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->enum('status', ['queued', 'processed', 'ignored', 'failed']);
            $table->string('ignore_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('ai_whatsapp_outbound', function (Blueprint $table): void {
            $table->id();
            $table->string('wa_message_id')->unique();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->ulid('pending_action_id')->nullable()->index();
            $table->jsonb('picker')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_whatsapp_outbound');
        Schema::dropIfExists('ai_whatsapp_inbound');
        Schema::dropIfExists('ai_whatsapp_link_tokens');
        Schema::dropIfExists('ai_whatsapp_identities');
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'channel', 'last_message_at']);
            $table->dropColumn('channel');
        });
    }
};
