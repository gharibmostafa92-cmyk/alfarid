<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** task2 C1/H1: supplier portal administration and POS shift approvals are privileged. */
class Task2AdminAccessControlTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_retired_supplier_user_endpoints_reject_privileged_staff(): void
    {
        $supplier = Supplier::factory()->create();
        $token = $this->staffWith(['suppliers.update'])->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/admin/suppliers/{$supplier->id}/users", [
            'name' => 'Supplier User',
            'email' => 'supplier-user@example.test',
            'password' => 'supplier-pass-123',
        ])->assertStatus(410);

        $this->assertDatabaseCount('supplier_users', 0);
    }

    public function test_cashier_cannot_list_or_approve_shifts(): void
    {
        $token = $this->staffWith(['pos.access'])->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/shifts')->assertForbidden();
        // Route model binding resolves before the ability check, so an unknown shift is a 404, never a 200.
        $this->withToken($token)->postJson('/api/v1/admin/shifts/1/approve')->assertNotFound();
    }

    public function test_shift_viewer_can_list_shifts(): void
    {
        $token = $this->staffWith(['pos_shifts.view'])->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/shifts')->assertOk();
    }

    public function test_treasury_permission_does_not_grant_shift_review(): void
    {
        $token = $this->staffWith(['treasuries.view'])->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/shifts')->assertForbidden();
    }

    private function staffWith(array $permissions): User
    {
        $user = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        return $user;
    }
}
