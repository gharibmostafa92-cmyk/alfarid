<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Models\Branch;
use App\Models\User;
use App\Notifications\StaffRecipients;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Task6 Lane A3: staff recipient resolution + notification query path (F9 + F16).
 *
 * F9: withAnyPermission must eager-load roles/permissions (no N+1 per staff
 * row at every OrderCreated) and the empty-vs-null branch scope must be an
 * explicit policy: [] = central-only, null = every branch.
 * F16: index(?unread_only=1) must not run the unread COUNT twice, and readAll
 * must scan the partial index in its own DESC order.
 */
class Task6NotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->staff = User::factory()->create();
        $this->staff->assignRole(\Spatie\Permission\Models\Role::findOrCreate(\App\Enums\StaffRole::SuperAdmin->value, 'staff'));
        $this->token = $this->staff->createToken('audit')->plainTextToken;
    }

    public function test_recipients_eager_load_roles_to_avoid_n_plus_one(): void
    {
        $staff = StaffRecipients::withAnyPermission(['orders.view'], null);

        $this->assertNotEmpty($staff);
        foreach ($staff as $user) {
            $this->assertTrue($user->relationLoaded('roles'), 'roles must be eager-loaded for BranchAccess checks.');
        }
    }

    public function test_empty_branch_scope_is_central_only_while_null_means_every_branch(): void
    {
        $branch = Branch::factory()->create();
        $branchStaff = User::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'is_system' => false]);
        $branchStaff->givePermissionTo(Permission::findOrCreate('orders.view', 'staff'));

        $centralOnly = StaffRecipients::withAnyPermission(['orders.view'], []);
        $this->assertFalse(
            $centralOnly->contains(fn (User $u) => $u->id === $branchStaff->id),
            'Unallocated orders are central-only: branch-restricted staff must not be fanned out to.'
        );
        $this->assertTrue(
            $centralOnly->contains(fn (User $u) => $u->id === $this->staff->id),
            'Super Admin still receives unallocated-order notifications.'
        );

        $everyBranch = StaffRecipients::withAnyPermission(['orders.view'], null);
        $this->assertTrue($everyBranch->contains(fn (User $u) => $u->id === $branchStaff->id));
    }

    public function test_index_unread_only_runs_a_single_unread_count(): void
    {
        $this->staff->notify(new \App\Notifications\LowStockAlertNotification(1, 'SKU-1', 1, 0, 5));
        $this->staff->notify(new \App\Notifications\LowStockAlertNotification(2, 'SKU-2', 1, 0, 5));

        DB::enableQueryLog();
        $res = $this->withToken($this->token)->getJson('/api/v1/admin/notifications?unread_only=1');
        $res->assertOk()->assertJsonPath('meta.unread_count', 2)->assertJsonPath('meta.total', 2);

        $countQueries = collect(DB::getQueryLog())->filter(
            fn (array $entry) => preg_match('/^\s*select count/i', (string) ($entry['query'] ?? '')) === 1
        );
        $this->assertCount(1, $countQueries, 'unread_only=1 must reuse paginate() total instead of a second COUNT.');
    }
}
