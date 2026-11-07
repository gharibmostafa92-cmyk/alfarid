<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'lock_version')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->unsignedBigInteger('lock_version')->default(0);
            });
        }

        if (! Schema::hasTable('ai_attachments')) {
            Schema::create('ai_attachments', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->string('path');
                $t->string('mime', 50);
                $t->unsignedInteger('size');
                $t->char('sha256', 64);              // Content digest to detect and deduplicate duplicate image uploads
                $t->timestamp('consumed_at')->nullable();
                $t->timestamps();
                $t->index(['conversation_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_attachments');
        if (Schema::hasColumn('categories', 'lock_version')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->dropColumn('lock_version');
            });
        }
    }
};
