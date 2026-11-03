<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_reconcile_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->integer('checked')->default(0);
            $table->integer('problem_count')->default(0);
            $table->json('samples')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_reconcile_runs');
    }
};
