<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2: exactly-once POS stocktake scans. (stocktake_id, scan_id) is unique so
 * a retried HTTP request after a timeout cannot double-count. Rows are
 * deleted when the session is approved or cancelled (FK cascade on delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stocktake_id')->constrained('stocktakes')->cascadeOnDelete();
            $table->uuid('scan_id');
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 12, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['stocktake_id', 'scan_id'], 'stocktake_scans_unique_scan');
            $table->index(['stocktake_id', 'product_variant_id'], 'stocktake_scans_session_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_scans');
    }
};
