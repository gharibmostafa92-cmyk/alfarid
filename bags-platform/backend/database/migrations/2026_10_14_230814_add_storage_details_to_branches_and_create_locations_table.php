<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('code')->nullable();
            $table->enum('type', ['branch', 'warehouse'])->default('branch');
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::table('branches')->whereNull('code')->orderBy('id')->chunkById(100, function ($branches): void {
            foreach ($branches as $branch) {
                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update(['code' => 'BR-'.str_pad((string) $branch->id, 3, '0', STR_PAD_LEFT)]);
            }
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->string('code')->nullable(false)->unique()->change();
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->enum('type', ['shelf', 'zone', 'bin', 'damaged', 'receiving'])->default('shelf');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropUnique('branches_code_unique');
            $table->dropColumn(['code', 'type', 'phone', 'email', 'address', 'city', 'is_active']);
        });
    }
};
