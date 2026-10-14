<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index('branch_id', 'users_branch_id_index');
            $table->foreign('branch_id', 'users_branch_id_foreign')
                ->references('id')
                ->on('branches')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign('users_branch_id_foreign');
            $table->dropIndex('users_branch_id_index');
        });
    }
};
