<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use Carbon\CarbonInterval;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StaffSettingsCommitTest extends TestCase
{
    use DatabaseMigrations;

    public function test_committed_cache_is_readable_inside_transactions_without_reading_all_settings_again(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        $service->clearCache();
        $service->get('company_name');
        DB::beginTransaction();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame('Alfarid', $service->get('company_name'));
            $this->assertSame(14.0, $service->get('default_tax_rate'));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::rollBack();
        }
        $this->assertSame([], $queries);
    }

    public function test_a_delayed_cache_writer_cannot_publish_old_settings_to_the_current_version(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        $service->clearCache();
        $manager = Cache::getFacadeRoot();
        $repository = $manager->store();
        $oldKey = null;
        $proxy = Mockery::mock($manager);
        $proxy->shouldReceive('remember')->once()->andReturnUsing(
            function ($key, $ttl, $read) use ($service, $repository, &$oldKey) {
                $oldKey = $key;
                $old = $read();
                // The writer commits and rotates the version after the reader's SELECT,
                // then the delayed reader finally fills the cache with its old snapshot.
                $service->updateBatch(['company_name' => 'Current company']);
                $repository->put($key, $old, $ttl);

                return $old;
            });
        Cache::swap($proxy);
        try {
            $this->assertSame('Alfarid', $service->get('company_name'));
        } finally {
            Cache::swap($manager);
        }
        $this->assertSame('Alfarid', $repository->get($oldKey)['company']['company_name']);
        $this->assertSame('Current company', $service->get('company_name'));
    }

    public function test_permission_retirement_rollback_restores_role_and_direct_assignments(): void
    {
        $migration = require database_path('migrations/2026_10_25_000003_retire_unused_staff_permissions.php');
        $migration->down();
        try {
            $permission = Permission::findOrCreate('warehouses.view', 'staff');
            $role = Role::create(['name' => 'Retirement test', 'guard_name' => 'staff']);
            $role->givePermissionTo($permission);
            $staff = User::factory()->create();
            $staff->givePermissionTo($permission);
            $migration->up();
            $this->assertDatabaseMissing('permissions', ['name' => 'warehouses.view']);
            $migration->down();
            $restored = Permission::findByName('warehouses.view', 'staff');
            $this->assertDatabaseHas('role_has_permissions', ['role_id' => $role->id, 'permission_id' => $restored->id]);
            $this->assertDatabaseHas('model_has_permissions', ['model_id' => $staff->id,
                'model_type' => User::class, 'permission_id' => $restored->id]);
        } finally {
            if (! Schema::hasTable('retired_staff_permissions')) {
                $migration->up();
            }
        }
    }

    public function test_post_commit_permission_cache_failure_does_not_turn_a_saved_change_into_a_server_error(): void
    {
        $role = Role::create(['name' => 'Before', 'guard_name' => 'staff']);
        $registrar = Mockery::mock(PermissionRegistrar::class);
        $registrar->shouldReceive('forgetCachedPermissions')->andThrow(new \RuntimeException('Cache unavailable'));
        $this->app->instance(PermissionRegistrar::class, $registrar);
        DB::transaction(function () use ($role): void {
            DB::table('roles')->where('id', $role->id)->update(['name' => 'Committed']);
            StaffAdministration::clearPermissionCacheAfterCommit();
        });
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'Committed']);
        $this->assertLessThanOrEqual(300, CarbonInterval::instance(config('permission.cache.expiration_time'))->totalSeconds);
    }

    public function test_repeated_static_setting_reads_are_served_from_cache_without_sequence_queries(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        $service->clearCache();
        $service->get('default_tax_rate');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame(14.0, $service->get('default_tax_rate'));
        $this->assertSame('Alfarid', $service->get('company_name'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $queries);
    }

    public function test_sequence_sync_failure_reports_a_warning_after_committing_settings_and_can_be_retried(): void
    {
        $this->seed(SettingSeeder::class);
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate('settings.manage', 'staff'));
        $actor->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        $token = $actor->createToken('settings')->plainTextToken;
        DB::statement('ALTER FUNCTION document_next_number(text, bigint) RENAME TO test_unavailable_document_next_number');
        try {
            $this->withToken($token)->putJson('/api/v1/admin/settings', [
                'company_name' => 'Committed company', 'order_next_number' => 2000,
                'expected' => ['company_name' => 'Alfarid', 'order_next_number' => 1000],
            ])->assertOk()->assertJsonCount(1, 'warnings')
                ->assertJsonPath('data.company.company_name', 'Committed company');
            $this->assertDatabaseHas('settings', ['key' => 'order_next_number', 'value' => '2000']);
        } finally {
            DB::statement('ALTER FUNCTION test_unavailable_document_next_number(text, bigint) RENAME TO document_next_number');
        }
        $this->withToken($token)->putJson('/api/v1/admin/settings', ['order_next_number' => 2000, 'expected' => ['order_next_number' => 1000]])
            ->assertOk()->assertJsonCount(0, 'warnings')->assertJsonPath('data.numbering.order_next_number', 2000);
        $this->assertSame('ORD-002000', app(SettingService::class)->generateNextNumber('order'));
    }
}
