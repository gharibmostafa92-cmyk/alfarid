<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Customer\CustomerStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task5StaffAdminSlice3Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_update_user_rejects_case_insensitive_duplicate_email_with_422(): void
    {
        $branch = Branch::factory()->create();
        $role = Role::findOrCreate('Super Admin', 'staff');
        $role->givePermissionTo(Permission::findOrCreate('users.manage', 'staff'));

        $admin = User::factory()->create([
            'branch_id' => $branch->id,
            'password' => 'AdminPass1234',
        ]);
        $admin->assignRole($role);

        $existing = User::factory()->create(['branch_id' => $branch->id]);
        DB::table('users')->where('id', $existing->id)->update(['email' => 'MixedCase@Example.com']);

        $target = User::factory()->create(['branch_id' => $branch->id, 'email' => 'target@example.com'])->refresh();

        $token = $admin->createToken('staff')->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/v1/admin/users/{$target->id}", [
                'expected_version' => $target->lock_version,
                'email' => 'mixedcase@example.com',
                'current_password' => 'AdminPass1234',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_role_permission_seeder_includes_customer_approve_and_documents_view_and_manage_fallback_works(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertDatabaseHas('permissions', ['name' => 'customers.approve', 'guard_name' => 'staff']);
        $this->assertDatabaseHas('permissions', ['name' => 'customers.documents.view', 'guard_name' => 'staff']);

        $managerRole = Role::findOrCreate('customer-manager', 'staff');
        $managerRole->givePermissionTo('customers.manage');

        $staff = User::factory()->create();
        $staff->assignRole($managerRole);

        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);

        $token = $staff->createToken('staff')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/customers')->assertOk();
        // Reaches CustomerManagementService::approve (422 missing document) instead of 403 Forbidden.
        $this->withToken($token)->postJson("/api/v1/admin/customers/{$customer->id}/approve")->assertStatus(422);
    }

    public function test_audit_staff_administration_logs_customers_suppliers_and_ai_administration(): void
    {
        $role = Role::findOrCreate('viewer', 'staff');
        $staff = User::factory()->create();
        $staff->assignRole($role);

        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $token = $staff->createToken('staff')->plainTextToken;

        // Denied AI settings mutation must be audited as 'ai_administration', not 'ai_conversation'.
        $this->withToken($token)
            ->putJson('/api/v1/admin/ai/settings', ['ai_enabled' => false])
            ->assertForbidden();

        $this->assertDatabaseHas('staff_audit_logs', [
            'actor_id' => $staff->id,
            'action' => 'administration.denied',
            'target_type' => 'ai_administration',
        ]);

        // Denied customer approval mutation must be audited under 'customer'.
        $this->withToken($token)
            ->postJson("/api/v1/admin/customers/{$customer->id}/approve")
            ->assertForbidden();

        $this->assertDatabaseHas('staff_audit_logs', [
            'actor_id' => $staff->id,
            'action' => 'administration.denied',
            'target_type' => 'customer',
            'target_id' => $customer->id,
        ]);
    }
}
