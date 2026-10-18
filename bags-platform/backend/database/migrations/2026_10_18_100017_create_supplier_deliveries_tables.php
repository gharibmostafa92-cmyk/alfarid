<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 10 — إشعارات توريد الموردين (ASN): شحنات جزئية/كلية مقابل أمر شراء
 * مع فاتورة مبدئية مرفوعة، يستلمها المخزن لاحقًا عبر استلام أمر الشراء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_number')->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('status', 20)->default('pending_receipt');
            $table->date('delivery_date');
            $table->string('invoice_file_path')->nullable();
            $table->string('supplier_invoice_no')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
        });

        DB::statement("ALTER TABLE supplier_deliveries ADD CONSTRAINT supplier_deliveries_status_check CHECK (status IN ('pending_receipt', 'received', 'rejected'))");

        Schema::create('supplier_delivery_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_delivery_id')->constrained('supplier_deliveries')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items');
            $table->foreignId('product_variant_id')->constrained('product_variants');
            $table->decimal('shipped_qty', 12, 4);
            $table->decimal('received_qty', 12, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_delivery_items');
        Schema::dropIfExists('supplier_deliveries');
    }
};
