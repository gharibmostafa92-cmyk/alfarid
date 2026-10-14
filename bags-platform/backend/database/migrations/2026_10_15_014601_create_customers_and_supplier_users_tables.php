<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->enum('type', ['retail', 'wholesale', 'distributor'])->default('retail');
            $table->enum('status', ['pending', 'approved', 'rejected', 'blocked'])->default('pending');
            $table->string('tax_number')->nullable();
            $table->string('commercial_register')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_users');
        Schema::dropIfExists('customers');
    }
};
