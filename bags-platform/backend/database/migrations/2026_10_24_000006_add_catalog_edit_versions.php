<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'product_variants'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('lock_version')->default(0);
            });
        }
        Schema::table('products', fn (Blueprint $table) => $table->string('create_request_hash', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('create_request_hash'));
        foreach (['products', 'product_variants'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('lock_version'));
        }
    }
};
