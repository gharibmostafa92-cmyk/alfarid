<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_order_event_streams', function (Blueprint $table): void {
            $table->foreignId('branch_id')->primary()->constrained('branches')->cascadeOnDelete();
            $table->unsignedBigInteger('position')->default(0);
        });
        Schema::create('pos_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->unsignedBigInteger('position')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->string('kind', 12);
            $table->timestamp('created_at')->index();
            $table->unique(['branch_id', 'position']);
            $table->index(['branch_id', 'position', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_events');
        Schema::dropIfExists('pos_order_event_streams');
    }
};
