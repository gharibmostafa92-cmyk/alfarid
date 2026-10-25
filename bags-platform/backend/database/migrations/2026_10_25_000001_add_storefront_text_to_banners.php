<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            $table->string('kicker', 60)->nullable()->after('title');
            $table->string('headline', 80)->nullable()->after('kicker');
            $table->string('headline_bold', 80)->nullable()->after('headline');
            $table->string('description', 240)->nullable()->after('headline_bold');
            $table->string('cta_label', 30)->nullable()->after('description');
            $table->string('bg_color', 7)->nullable()->after('cta_label');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            $table->dropColumn(['kicker', 'headline', 'headline_bold', 'description', 'cta_label', 'bg_color']);
        });
    }
};
