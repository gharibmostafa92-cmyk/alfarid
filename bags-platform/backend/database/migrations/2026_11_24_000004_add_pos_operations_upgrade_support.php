<?php

declare(strict_types=1);

use App\Enums\StaffRole;
use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'prepared_at') || ! Schema::hasColumn('orders', 'prepared_by')) {
            Schema::table('orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('orders', 'prepared_at')) {
                    $table->timestamp('prepared_at')->nullable()->after('returned_at');
                }
                if (! Schema::hasColumn('orders', 'prepared_by')) {
                    $table->foreignId('prepared_by')->nullable()->after('prepared_at')->constrained('users')->nullOnDelete();
                }
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            ConcurrentIndex::ensure(
                'expenses_shift_id_id_idx',
                'ON expenses (shift_id, id DESC) WHERE shift_id IS NOT NULL',
            );
            ConcurrentIndex::ensure(
                'orders_branch_status_id_idx',
                'ON orders (allocated_branch_id, status, id DESC) WHERE deleted_at IS NULL',
            );
        } else {
            Schema::table('expenses', function (Blueprint $table): void {
                $table->index(['shift_id', 'id'], 'expenses_shift_id_id_idx');
            });
            Schema::table('orders', function (Blueprint $table): void {
                $table->index(['allocated_branch_id', 'status', 'id'], 'orders_branch_status_id_idx');
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('pos.orders.cancel', 'staff');
        $superAdmin = Role::query()
            ->where('name', StaffRole::SuperAdmin->value)
            ->where('guard_name', 'staff')
            ->first();
        if ($superAdmin !== null) {
            $superAdmin->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            ConcurrentIndex::drop('orders_branch_status_id_idx');
            ConcurrentIndex::drop('expenses_shift_id_id_idx');
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropIndex('orders_branch_status_id_idx');
            });
            Schema::table('expenses', function (Blueprint $table): void {
                $table->dropIndex('expenses_shift_id_id_idx');
            });
        }

        if (Schema::hasColumn('orders', 'prepared_by') || Schema::hasColumn('orders', 'prepared_at')) {
            Schema::table('orders', function (Blueprint $table): void {
                if (Schema::hasColumn('orders', 'prepared_by')) {
                    $table->dropConstrainedForeignId('prepared_by');
                }
                if (Schema::hasColumn('orders', 'prepared_at')) {
                    $table->dropColumn('prepared_at');
                }
            });
        }
    }
};
