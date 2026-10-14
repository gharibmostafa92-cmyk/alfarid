<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BranchEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_branch_crud_returns_validated_branch_data_and_pagination(): void
    {
        $admin = $this->staffWithPermission('branches.manage');
        $token = $admin->createToken('test')->plainTextToken;

        $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/branches', [
            'name' => 'Main Store',
            'code' => 'BR-001',
            'type' => 'branch',
            'phone' => '+201001234567',
            'email' => 'main@example.test',
            'address' => '1 Market Street',
            'city' => 'Cairo',
            'is_active' => true,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Main Store')
            ->assertJsonPath('data.code', 'BR-001')
            ->assertJsonPath('data.type', 'branch')
            ->assertJsonPath('data.is_active', true);

        $branchId = $created->json('data.id');

        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/branches/{$branchId}", [
            'name' => 'Central Warehouse',
            'type' => 'warehouse',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Central Warehouse')
            ->assertJsonPath('data.type', 'warehouse');

        $this->requestWithToken($token, 'GET', "/api/v1/admin/branches/{$branchId}")
            ->assertOk()
            ->assertJsonPath('data.code', 'BR-001');

        $this->requestWithToken($token, 'GET', '/api/v1/admin/branches?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_branch_validation_rejects_duplicate_codes_and_unknown_types(): void
    {
        Branch::factory()->create(['code' => 'BR-050']);
        $admin = $this->staffWithPermission('branches.manage');
        $token = $admin->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'POST', '/api/v1/admin/branches', [
            'name' => 'Invalid Branch',
            'code' => 'BR-050',
            'type' => 'shop',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'type']);
    }

    public function test_branch_cannot_be_deleted_while_staff_are_assigned(): void
    {
        $branch = Branch::factory()->create();
        User::factory()->create(['branch_id' => $branch->id]);
        $admin = $this->staffWithPermission('branches.manage');
        $token = $admin->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/branches/{$branch->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
    }

    public function test_branch_cannot_be_deleted_while_stock_movements_reference_it(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
        });

        $branch = Branch::factory()->create();
        DB::table('stock_movements')->insert(['branch_id' => $branch->id]);
        $admin = $this->staffWithPermission('branches.manage');
        $token = $admin->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/branches/{$branch->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('stock_movements', ['branch_id' => $branch->id]);
    }

    public function test_deleting_an_unreferenced_branch_cascades_to_its_locations(): void
    {
        $branch = Branch::factory()->create();
        $location = $branch->locations()->create([
            'name' => 'Rack A',
            'code' => 'A-01-01',
            'type' => 'shelf',
        ]);
        $admin = $this->staffWithPermission('branches.manage');
        $token = $admin->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/branches/{$branch->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    private function staffWithPermission(string $permissionName): User
    {
        $permission = Permission::findOrCreate($permissionName, 'staff');
        $staff = User::factory()->create();
        $staff->givePermissionTo($permission);

        return $staff;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }
}
