<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AiAgentPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = array_map(fn ($name) => Permission::findOrCreate('ai_agent.'.$name, 'staff'), ['use', 'execute', 'logs.view', 'settings']);
        $superAdmin = Role::query()->where('guard_name', 'staff')->where('name', StaffRole::SuperAdmin->value)->first();
        $superAdmin?->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
