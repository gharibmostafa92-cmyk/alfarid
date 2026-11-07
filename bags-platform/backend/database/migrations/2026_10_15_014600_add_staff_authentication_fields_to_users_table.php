<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard against duplicate column errors if existing environments pre-created staff columns.
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('branch_id');
            }
        });
    }

    public function down(): void
    {
        // Drop conditionally to protect existing columns (such as pre-existing phone) from accidental drop.
        Schema::table('users', function (Blueprint $table): void {
            $drop = [];
            if (Schema::hasColumn('users', 'branch_id')) {
                $drop[] = 'branch_id';
            }
            if (Schema::hasColumn('users', 'is_active')) {
                $drop[] = 'is_active';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
