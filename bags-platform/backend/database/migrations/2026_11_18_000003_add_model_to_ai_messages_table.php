<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->string('model', 100)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->dropColumn('model');
        });
    }
};
