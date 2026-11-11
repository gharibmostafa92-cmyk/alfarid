<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const LEGACY_TYPES = "'purchase_receipt', 'sale', 'sale_return', 'adjustment_in', 'adjustment_out', 'damage', 'transfer_out', 'transfer_in', 'stocktake_gain', 'stocktake_loss', 'reserve', 'release_reserve', 'damage_release', 'damage_write_off', 'transfer_in_transit', 'transfer_transit_release', 'transfer_shortage'";

    public function up(): void
    {
        $this->replaceTypes(self::LEGACY_TYPES.", 'production_receipt'");
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            if (Schema::hasTable('production_receipts')) {
                return;
            }

            Schema::create('production_receipts', function (Blueprint $table) {
                $table->id();
                $table->string('batch_number', 100)->unique();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->string('idempotency_key', 128);
                $table->string('request_hash', 64);
                $table->json('items');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['created_by', 'idempotency_key']);
                $table->index(['branch_id', 'id']);
            });
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('production_receipts') && DB::table('production_receipts')->exists()) || DB::table('stock_movements')->where('type', 'production_receipt')->exists()) {
            throw new RuntimeException('Cannot roll back recorded production receipts; preserve the inventory audit trail.');
        }
        $this->replaceTypes(self::LEGACY_TYPES);
        Schema::dropIfExists('production_receipts');
    }

    private function replaceTypes(string $types): void
    {
        DB::transaction(function () use ($types): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");
            DB::statement("ALTER TABLE stock_movements
                DROP CONSTRAINT stock_movements_valid_type,
                ADD CONSTRAINT stock_movements_valid_type CHECK (type IN ({$types})) NOT VALID");
        });
        DB::statement('ALTER TABLE stock_movements VALIDATE CONSTRAINT stock_movements_valid_type');
    }
};
