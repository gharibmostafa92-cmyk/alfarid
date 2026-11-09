<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Branches;

use App\Models\Branch;
use App\Models\Location;
use App\Models\PosShift;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Treasury;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

class BranchLifecycleConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

    #[TestWith(['shift', true])]
    #[TestWith(['shift', false])]
    #[TestWith(['treasury', true])]
    #[TestWith(['treasury', false])]
    public function test_branch_deactivation_and_financial_activity_serialize_in_both_orders(string $activity, bool $activityFirst): void
    {
        $this->seed(SettingSeeder::class);
        $admin = User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $treasury = Treasury::create(['name' => 'Race drawer', 'code' => 'RACE-DRAWER', 'branch_id' => $branch->id,
            'type' => 'pos_drawer', 'current_balance' => 0, 'is_active' => true]);
        $deactivate = 'app(App\\Actions\\Branches\\UpdateBranch::class)(App\\Models\\User::findOrFail('.$admin->id.'), '
            .'App\\Models\\Branch::findOrFail('.$branch->id.'), ["is_active" => false, "lock_version" => 0]);';
        $active = $activity === 'shift'
            ? 'app(App\\Services\\PosService::class)->openShift(App\\Models\\User::findOrFail('.$cashier->id.'), '.$branch->id.', '.$treasury->id.', 0.0);'
            : 'app(App\\Services\\TreasuryService::class)->record(["treasury_id" => '.$treasury->id.', "type" => App\\Enums\\TreasuryTransactionType::OpeningBalance, "amount" => 1, "created_by" => '.$cashier->id.']);';
        // Warm branch access before the race, so a cached active branch cannot bypass the fresh lock check.
        $warm = 'app(App\\Services\\BranchAccess::class)->assertBranch('.$cashier->id.', '.$branch->id.');';
        $job = function (string $operation, ?string $lock, string $prepare = '') use ($branch): string {
            return $prepare.'try { return Illuminate\\Support\\Facades\\DB::transaction(function () use ($ready) {'
                .($lock ? 'App\\Models\\Branch::whereKey('.$branch->id.')->'.$lock.'()->firstOrFail();' : '')
                .'$ready(); '.$operation.' return true; });'
                .'} catch (Illuminate\\Auth\\Access\\AuthorizationException|App\\Exceptions\\BusinessException|Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface $e) { return false; }';
        };
        $results = $this->concurrentJobs([
            $job($deactivate, $activityFirst ? null : 'lockForUpdate'),
            $job($active, $activityFirst ? 'sharedLock' : null, $warm),
        ]);
        $this->assertSame([$activityFirst === false, $activityFirst], $results);
        $this->assertSame($activityFirst, (bool) $branch->fresh()->is_active);
        $this->assertSame($activity === 'shift' && $activityFirst ? 1 : 0, PosShift::where('branch_id', $branch->id)->where('status', 'open')->count());
        $this->assertSame($activity === 'treasury' && $activityFirst ? '1.00' : '0.00', $treasury->fresh()->current_balance);
    }

    public function test_location_updates_without_versions_have_explicit_last_writer_wins_behavior(): void
    {
        $admin = User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $branch = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $token = $admin->createToken('race')->plainTextToken;
        $jobs = array_map(fn ($name) => $this->httpJob($token, 'PUT', '/api/v1/admin/branches/'.$branch->id.'/locations/'.$location->id, ['name' => $name]), ['Location A', 'Location B']);
        $results = $this->concurrentJobs($jobs);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertContains($location->fresh()->name, ['Location A', 'Location B']);
    }

    public function test_two_staff_email_updates_cannot_claim_the_same_address(): void
    {
        $admin = User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $token = $admin->createToken('race')->plainTextToken;
        $targets = User::factory()->count(2)->create();
        $jobs = $targets->map(fn ($user) => $this->httpJob($token, 'PUT', '/api/v1/admin/users/'.$user->id,
            ['expected_version' => (int) $user->lock_version, 'email' => 'same-new-address@example.test']))->all();
        $results = $this->concurrentJobs($jobs);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'status'));
        $this->assertSame(1, User::where('email', 'same-new-address@example.test')->count());
    }

    public function test_duplicate_location_codes_in_one_branch_return_validation_errors_under_concurrency(): void
    {
        $admin = User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $branch = Branch::factory()->create();
        $job = $this->httpJob($admin->createToken('race')->plainTextToken, 'POST', '/api/v1/admin/branches/'.$branch->id.'/locations',
            ['name' => 'Same location', 'code' => 'SAME-CODE', 'type' => 'shelf']);
        $results = $this->concurrentJobs([$job, $job]);
        $this->assertEqualsCanonicalizing([201, 422], array_column($results, 'status'));
        $this->assertSame(1, Location::where('branch_id', $branch->id)->where('code', 'SAME-CODE')->count());
    }

    #[TestWith(['branch'])]
    #[TestWith(['location'])]
    public function test_deletion_racing_the_first_stock_movement_never_leaves_orphans_or_returns_500(string $resource): void
    {
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $token = $admin->createToken('race')->plainTextToken;
        for ($trial = 0; $trial < 3; $trial++) {
            $branch = Branch::factory()->create();
            $location = Location::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
            $variant = ProductVariant::factory()->create(['is_active' => true]);
            $uri = '/api/v1/admin/branches/'.$branch->id.($resource === 'location' ? '/locations/'.$location->id : '');
            $jobs = [
                $this->httpJob($token, 'DELETE', $uri),
                $this->httpJob($token, 'POST', '/api/v1/admin/inventory/adjustments', [
                    'branch_id' => $branch->id, 'location_id' => $location->id,
                    'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
                ], ['HTTP_IDEMPOTENCY_KEY' => 'deletion-race-'.$resource.'-'.$trial]),
            ];
            if ($trial < 2) {
                $first = $trial;
                $model = $resource === 'branch' ? Branch::class : Location::class;
                $id = $resource === 'branch' ? $branch->id : $location->id;
                $lock = $trial === 0 ? 'lockForUpdate' : 'sharedLock';
                $jobs[$first] = 'return Illuminate\\Support\\Facades\\DB::transaction(function () use ($ready) {'
                    .$model.'::whereKey('.$id.')->'.$lock.'()->firstOrFail();'.$jobs[$first].'});';
            }
            $results = $this->concurrentJobs($jobs);
            $this->assertContains($results[0]['status'], [204, 409, 422], json_encode($results, JSON_UNESCAPED_UNICODE));
            $this->assertContains($results[1]['status'], [201, 403, 404, 409, 422], json_encode($results, JSON_UNESCAPED_UNICODE));
            $this->assertTrue($results[0]['status'] === 204 || $results[1]['status'] === 201);
            if ($trial === 0) {
                $this->assertSame(204, $results[0]['status']);
            } elseif ($trial === 1) {
                $this->assertSame(201, $results[1]['status']);
            }
            if ($results[0]['status'] === 204) {
                $this->assertSame(0, StockMovement::where('branch_id', $branch->id)->count());
                $this->assertSame(0, StockLevel::where('product_variant_id', $variant->id)->count());
            } else {
                $this->assertNotNull($branch->fresh());
                $this->assertNotNull($location->fresh());
                $this->assertSame(1, StockMovement::where('branch_id', $branch->id)->count());
                $this->assertSame(1.0, (float) StockLevel::where('product_variant_id', $variant->id)->sum('on_hand'));
            }
        }
    }
}
