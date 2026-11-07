<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provisions the suppliers table and constrains supplier_users.supplier_id with a foreign key.
 * Tracks accounts payable balances adjusted upon invoice approvals and payment disbursement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('commercial_register')->nullable();
            $table->string('address')->nullable();
            $table->unsignedInteger('payment_terms_days')->default(0);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('supplier_users', function (Blueprint $table): void {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_users', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
        });
        Schema::dropIfExists('suppliers');
    }
};
