<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3: تعديل الفرع من الشاشة كان بلا قفل توافقي — آخر واحد بيكسب.
 * آدينا lock_version زي الموظفين، إلزامي في UpdateBranchRequest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->unsignedBigInteger('lock_version')->default(0));
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('lock_version'));
    }
};
