<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Columns are transactional; the independent repair migration builds indexes.
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'occasions')) {
                $table->jsonb('occasions')->nullable();
            }
            if (! Schema::hasColumn('products', 'badge_en')) {
                $table->string('badge_en', 30)->nullable();
            }
            if (! Schema::hasColumn('products', 'badge_ar')) {
                $table->string('badge_ar', 30)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['occasions', 'badge_en', 'badge_ar']));
    }
};
