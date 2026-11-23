<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_item_document_drafts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('mode', 16);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->json('rows');
            $table->json('edits');
            $table->unsignedInteger('version')->default(0);
            $table->char('source_hash', 64);
            $table->char('received_source_hash', 64)->nullable()->unique();
            $table->foreignId('receipt_id')->nullable()->constrained('production_receipts');
        });
        Schema::create('ai_supplier_variant_codes', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_code', 128);
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->primary(['supplier_id', 'supplier_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_supplier_variant_codes');
        Schema::dropIfExists('ai_item_document_drafts');
    }
};
