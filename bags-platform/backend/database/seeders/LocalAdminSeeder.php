<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocalAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('This admin seeder is only available locally.');
        }

        $password = env('LOCAL_ADMIN_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new \LogicException('Set LOCAL_ADMIN_PASSWORD to at least 12 characters.');
        }

        DB::transaction(function () use ($password): void {
            $existing = User::query()->where('email', 'admin@alfarid.local')->first();
            if ($existing !== null) {
                if (! $existing->hasRole(StaffRole::SuperAdmin->value)) {
                    throw new \LogicException('The admin email is already in use by another staff account.');
                }

                $this->command?->info('Admin already exists; password and account were left unchanged.');

                return;
            }

            $this->call(RolePermissionSeeder::class);
            $admin = User::query()->create([
                'name' => 'مدير النظام',
                'email' => 'admin@alfarid.local',
                'password' => $password,
                'is_active' => true,
            ]);
            $admin->assignRole(StaffRole::SuperAdmin->value);
            $this->command?->info('Local admin created: admin@alfarid.local');
        });
    }
}
