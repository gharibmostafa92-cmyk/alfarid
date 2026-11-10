<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* F22: Spatie models and StaffAdministration inlined as raw DB + Cache calls;
 * migrations must not depend on app classes that may be renamed or deleted. */
return new class extends Migration
{
    private const UNUSED = [
        'inventory.create', 'inventory.update', 'inventory.delete', 'inventory.manage',
        'warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.delete', 'warehouses.manage',
        'sales.view', 'sales.create', 'sales.update', 'sales.delete', 'sales.manage',
    ];

    public function up(): void
    {
        Schema::create('retired_staff_permissions', function (Blueprint $table): void {
            $table->id();
            $table->jsonb('snapshot');
        });
        foreach (DB::table('permissions')->where('guard_name', 'staff')->whereIn('name', self::UNUSED)->get() as $permission) {
            DB::table('retired_staff_permissions')->insert(['snapshot' => json_encode([
                'permission' => (array) $permission,
                'roles' => DB::table('role_has_permissions')->where('permission_id', $permission->id)->get()->all(),
                'models' => DB::table('model_has_permissions')->where('permission_id', $permission->id)->get()->all(),
            ], JSON_THROW_ON_ERROR)]);
        }
        DB::table('permissions')->where('guard_name', 'staff')->whereIn('name', self::UNUSED)->delete();
        DB::afterCommit(fn () => Cache::forget('spatie.permission.cache'));
    }

    public function down(): void
    {
        if (! Schema::hasTable('retired_staff_permissions')) {
            // An installation that ran the older migration has no recoverable assignment snapshot.
            throw new RuntimeException('Cannot restore retired permissions without a snapshot; restore them from a database backup.');
        }
        foreach (DB::table('retired_staff_permissions')->orderBy('id')->get() as $row) {
            $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
            DB::table('permissions')->insertOrIgnore([
                'name' => $snapshot['permission']['name'],
                'guard_name' => 'staff',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $permissionId = (int) DB::table('permissions')
                ->where('name', $snapshot['permission']['name'])
                ->where('guard_name', 'staff')
                ->value('id');
            foreach (['roles' => 'role_has_permissions', 'models' => 'model_has_permissions'] as $key => $table) {
                foreach ($snapshot[$key] as $assignment) {
                    if ($key === 'roles' && ! DB::table('roles')->where('id', $assignment['role_id'])->exists()) {
                        continue;
                    }
                    $assignment['permission_id'] = $permissionId;
                    DB::table($table)->insertOrIgnore($assignment);
                }
            }
        }
        Schema::drop('retired_staff_permissions');
        DB::afterCommit(fn () => Cache::forget('spatie.permission.cache'));
    }
};
