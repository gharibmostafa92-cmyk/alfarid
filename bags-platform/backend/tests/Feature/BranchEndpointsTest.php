<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'code' => '  br-001  ',
            'type' => 'branch',
            'phone' => '+201001234567',
            'email' => 'Main@Example.Test',
            'address' => '1 Market Street',
            'city' => 'Cairo',
            'is_active' => true,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Main Store')
            ->assertJsonPath('data.code', 'BR-001')
            ->assertJsonPath('data.email', 'main@example.test')
            ->assertJsonPath('data.type', 'branch')
            ->assertJsonPath('data.is_active', true);

        $branchId = $created->json('data.id');

        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/branches/{$branchId}", [
            'name' => 'Central Warehouse',
            'code' => ' br-001 ',
            'type' => 'warehouse',
            // M3: optimistic concurrency is mandatory on branch updates.
            'lock_version' => $created->json('data.lock_version'),
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
            'code' => ' br-050 ',
            'type' => 'shop',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'type']);
    }

    public function test_branch_can_be_deactivated_and_reactivated(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $token = $this->staffWithPermission('branches.manage')->createToken('test')->plainTextToken;

        foreach ([false, true] as $active) {
            $this->requestWithToken($token, 'PATCH', "/api/v1/admin/branches/{$branch->id}", [
                'is_active' => $active,
                // M3: optimistic concurrency is mandatory on branch updates.
                'lock_version' => $branch->fresh()->lock_version,
            ])->assertOk()->assertJsonPath('data.is_active', $active);

            $this->assertSame($active, $branch->fresh()->is_active);
        }
    }

    public function test_branch_update_without_lock_version_is_rejected(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $token = $this->staffWithPermission('branches.manage')->createToken('test')->plainTextToken;

        // Optimistic locking is mandatory: a missing lock_version is a 422.
        $this->requestWithToken($token, 'PATCH', "/api/v1/admin/branches/{$branch->id}", [
            'is_active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');

        $this->assertTrue((bool) $branch->fresh()->is_active);
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
        $createdTemporaryTable = ! Schema::hasTable('stock_movements');

        if ($createdTemporaryTable && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A MySQL test fixture needs a real stock_movements migration to avoid implicit DDL commits.');
        }

        if ($createdTemporaryTable) {
            Schema::create('stock_movements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            });
        }

        try {
            $branch = Branch::factory()->create();
            $admin = $this->staffWithPermission('branches.manage');
            if ($createdTemporaryTable) {
                DB::table('stock_movements')->insert(['branch_id' => $branch->id]);
            } else {
                $this->seed(SettingSeeder::class);
                app(InventoryService::class)->recordMovement([
                    'branch_id' => $branch->id,
                    'product_variant_id' => ProductVariant::factory()->create()->id,
                    'type' => 'adjustment_in', 'affected_bucket' => 'on_hand',
                    'quantity' => 1, 'created_by' => $admin->id,
                ]);
            }
            $token = $admin->createToken('test')->plainTextToken;

            $this->requestWithToken($token, 'DELETE', "/api/v1/admin/branches/{$branch->id}")
                ->assertStatus(409);

            $this->assertDatabaseHas('branches', ['id' => $branch->id]);
            $this->assertDatabaseHas('stock_movements', ['branch_id' => $branch->id]);
        } finally {
            if ($createdTemporaryTable) {
                Schema::dropIfExists('stock_movements');
            }
        }

        if ($createdTemporaryTable) {
            $this->assertFalse(Schema::hasTable('stock_movements'));
        }
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

    public function test_location_can_be_created_directly_with_its_branch_id(): void
    {
        $branch = Branch::factory()->create();

        $location = Location::create([
            'branch_id' => $branch->id,
            'name' => 'Directly Created Location',
            'code' => 'DIRECT-01',
            'type' => 'shelf',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'branch_id' => $branch->id,
        ]);
    }

    private function staffWithPermission(string $permissionName): User
    {
        $permission = Permission::findOrCreate($permissionName, 'staff');
        $staff = User::factory()->create();
        $staff->givePermissionTo($permission);
        // M4: إدارة الفروع مركزية — تتطلب النطاق الكلي بلا فرع مقيّد.
        $staff->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));

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
