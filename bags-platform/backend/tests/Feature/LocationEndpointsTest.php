<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LocationEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_location_crud_is_scoped_to_the_parent_branch(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $admin = $this->staffWithPermission('locations.manage');
        $token = $admin->createToken('test')->plainTextToken;

        $created = $this->requestWithToken($token, 'POST', "/api/v1/admin/branches/{$branch->id}/locations", [
            'name' => 'Aisle 1 Bin 2',
            'code' => ' a-01-02 ',
            'type' => 'bin',
            'is_active' => true,
            'branch_id' => $otherBranch->id,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Aisle 1 Bin 2')
            ->assertJsonPath('data.code', 'A-01-02')
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.type', 'bin');

        $locationId = $created->json('data.id');
        $otherBranchLocationUri = "/api/v1/admin/branches/{$otherBranch->id}/locations/{$locationId}";

        $this->requestWithToken($token, 'GET', $otherBranchLocationUri)->assertNotFound();
        $this->requestWithToken($token, 'PATCH', $otherBranchLocationUri, ['name' => 'Wrong Branch'])->assertNotFound();
        $this->requestWithToken($token, 'DELETE', $otherBranchLocationUri)->assertNotFound();

        $this->requestWithToken($token, 'GET', "/api/v1/admin/branches/{$branch->id}/locations?per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $locationId)
            ->assertJsonPath('meta.per_page', 1);

        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/branches/{$branch->id}/locations/{$locationId}", [
            'name' => 'Updated Bin',
            'code' => ' a-01-02 ',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Bin');

        $this->requestWithToken($token, 'DELETE', "/api/v1/admin/branches/{$branch->id}/locations/{$locationId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('locations', ['id' => $locationId]);
    }

    public function test_location_codes_are_unique_within_a_branch_but_may_repeat_in_another(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $admin = $this->staffWithPermission('locations.manage');
        $token = $admin->createToken('test')->plainTextToken;
        $payload = [
            'name' => 'Rack A',
            'code' => ' a-01-01 ',
            'type' => 'shelf',
        ];

        $this->requestWithToken($token, 'POST', "/api/v1/admin/branches/{$branch->id}/locations", $payload)
            ->assertCreated();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/branches/{$branch->id}/locations", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->requestWithToken($token, 'POST', "/api/v1/admin/branches/{$otherBranch->id}/locations", $payload)
            ->assertCreated();
    }

    public function test_location_cannot_be_deleted_while_stock_movements_reference_it(): void
    {
        $createdTemporaryTable = ! Schema::hasTable('stock_movements');

        if ($createdTemporaryTable && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A MySQL test fixture needs a real stock_movements migration to avoid implicit DDL commits.');
        }

        if ($createdTemporaryTable) {
            Schema::create('stock_movements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('location_id')->constrained()->restrictOnDelete();
            });
        }

        try {
            $branch = Branch::factory()->create();
            $location = $branch->locations()->create([
                'name' => 'Receiving Area',
                'code' => 'R-02',
                'type' => 'receiving',
            ]);
            DB::table('stock_movements')->insert(['location_id' => $location->id]);
            $admin = $this->staffWithPermission('locations.manage');
            $token = $admin->createToken('test')->plainTextToken;

            $this->requestWithToken(
                $token,
                'DELETE',
                "/api/v1/admin/branches/{$branch->id}/locations/{$location->id}",
            )->assertStatus(409);

            $this->assertDatabaseHas('locations', ['id' => $location->id]);
            $this->assertDatabaseHas('stock_movements', ['location_id' => $location->id]);
        } finally {
            if ($createdTemporaryTable) {
                Schema::dropIfExists('stock_movements');
            }
        }

        if ($createdTemporaryTable) {
            $this->assertFalse(Schema::hasTable('stock_movements'));
        }
    }

    public function test_location_endpoints_forbid_staff_without_location_permissions(): void
    {
        $branch = Branch::factory()->create();
        $location = $branch->locations()->create([
            'name' => 'Receiving Area',
            'code' => 'R-01',
            'type' => 'receiving',
        ]);
        $staff = User::factory()->create();
        $token = $staff->createToken('test')->plainTextToken;

        $this->requestWithToken($token, 'GET', "/api/v1/admin/branches/{$branch->id}/locations")
            ->assertForbidden();
        $this->requestWithToken($token, 'GET', "/api/v1/admin/branches/{$branch->id}/locations/{$location->id}")
            ->assertForbidden();
    }

    private function staffWithPermission(string $permissionName): User
    {
        $permission = Permission::findOrCreate($permissionName, 'staff');
        $staff = User::factory()->create();
        $staff->givePermissionTo($permission);

        return $staff;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }
}
