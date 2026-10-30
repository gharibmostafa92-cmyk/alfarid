<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_message_budgets', fn (Blueprint $table) => $table->integer('sends')->default(0)->change());
    }

    public function down(): void
    {
        // Keep the wider counter: existing production counts may exceed smallint.
    }
};
