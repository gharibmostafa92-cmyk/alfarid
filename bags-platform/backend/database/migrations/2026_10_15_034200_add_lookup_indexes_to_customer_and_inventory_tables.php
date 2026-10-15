<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->index(['type', 'status'], 'customers_type_status_index');
            $table->index('phone', 'customers_phone_index');
        });

        Schema::table('supplier_users', function (Blueprint $table): void {
            $table->index(['supplier_id', 'is_active'], 'supplier_users_supplier_active_index');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->index(['type', 'is_active'], 'branches_type_active_index');
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->index(['branch_id', 'is_active', 'type'], 'locations_branch_active_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropIndex('locations_branch_active_type_index');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropIndex('branches_type_active_index');
        });

        Schema::table('supplier_users', function (Blueprint $table): void {
            $table->dropIndex('supplier_users_supplier_active_index');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_phone_index');
            $table->dropIndex('customers_type_status_index');
        });
    }
};
