<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false);
        });
        // Include the original default identity if configuration changed since installation.
        DB::table('users')->whereIn('email', array_unique([
            'system@alfarid.local', (string) config('orders.system_user_email', 'system@alfarid.local'),
        ]))->update(['is_system' => true, 'is_active' => false]);
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', DB::table('users')->select('id')->where('is_system', true))->delete();
        Schema::create('staff_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->jsonb('metadata');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['target_type', 'target_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_audit_logs');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_system'));
    }
};
