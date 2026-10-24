<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'roles'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('lock_version')->default(0));
        }
    }

    public function down(): void
    {
        foreach (['users', 'roles'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('lock_version'));
        }
    }
};
