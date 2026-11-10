<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Actions\Staff\CreateStaffUser;
use App\Actions\Staff\DeactivateStaffUser;
use App\Models\AiWhatsappIdentity;
use App\Models\User;
use App\Services\Auth\StaffAdministration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// task3 Slice C (auth M8/M4/M2): deactivation revokes WhatsApp identity,
// manager-chosen passwords require rotation, admin lock restores lock_timeout.
class Task3StaffSliceCTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deactivation_revokes_whatsapp_identity(): void
    {
        $actor = $this->manager(['users.delete', 'users.manage']);
        $target = User::factory()->create(['is_active' => true]);
        AiWhatsappIdentity::create([
            'user_id' => $target->id,
            'phone_e164' => '+201000000001',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $this->assertTrue(app(DeactivateStaffUser::class)($actor, $target));

        $this->assertSame('revoked', AiWhatsappIdentity::where('user_id', $target->id)->value('status'));
    }

    public function test_manager_chosen_password_requires_rotation_on_first_login(): void
    {
        $actor = $this->manager(['users.create', 'users.manage']);

        $user = app(CreateStaffUser::class)($actor, [
            'name' => 'New Staff',
            'email' => 'new-staff@example.test',
            'password' => 'Manager-Chosen-123',
            'roles' => [],
        ])['user'];

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_generated_password_requires_rotation_on_first_login(): void
    {
        $actor = $this->manager(['users.create', 'users.manage']);

        $result = app(CreateStaffUser::class)($actor, [
            'name' => 'Gen Staff',
            'email' => 'gen-staff@example.test',
            'password' => 'ignored',
            'roles' => [],
        ], [], true);

        $this->assertNotNull($result['plain_password']);
        $this->assertTrue($result['user']->fresh()->must_change_password);
    }

    public function test_administration_lock_restores_previous_lock_timeout(): void
    {
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '3s'");

            StaffAdministration::lock();

            $this->assertSame('3s', DB::selectOne('SHOW lock_timeout')->lock_timeout);
        });
    }

    /** @param list<string> $permissions */
    private function manager(array $permissions): User
    {
        // Branch-unscoped managers need inventory.all_branches, otherwise
        // BranchAccess rejects every staff-administration action (403).
        $permissions[] = 'inventory.all_branches';
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $manager = User::factory()->create(['is_active' => true]);
        $manager->givePermissionTo($permissions);

        return $manager;
    }
}
