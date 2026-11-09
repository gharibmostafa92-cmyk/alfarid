<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Models\Branch;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

class StaffAuditScaleTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

    public function test_user_search_and_pagination_remain_correct_with_fifty_thousand_staff(): void
    {
        $admin = User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $template = User::factory()->raw();
        for ($offset = 0; $offset < 50000; $offset += 1000) {
            $rows = [];
            for ($i = $offset; $i < $offset + 1000; $i++) {
                $rows[] = array_replace($template, ['name' => 'Load Employee '.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'email' => 'load-'.$i.'@example.test', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('users')->insert($rows);
        }
        $token = $admin->createToken('scale')->plainTextToken;
        foreach (['' => 50001, 'Load Employee 49999' => 1, '%_\\' => 0] as $search => $total) {
            $started = microtime(true);
            $response = $this->withToken($token)->getJson('/api/v1/admin/users?per_page=100&search='.urlencode($search))->assertOk();
            $this->assertSame($total, $response->json('meta.total'));
            $this->assertCount(min(100, $total), $response->json('data'));
            $this->auditMetric(['case' => $this->name(), 'rows' => 50000, 'search' => $search,
                'wall_seconds' => microtime(true) - $started, 'peak_bytes' => memory_get_peak_usage(true)]);
        }
    }

    public function test_branch_pagination_and_related_counts_remain_correct_with_five_hundred_branches(): void
    {
        $admin = User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $branches = Branch::factory()->count(500)->create();
        $branch = $branches->first();
        User::factory()->count(3)->create(['branch_id' => $branch->id]);
        Location::factory()->count(2)->create(['branch_id' => $branch->id]);
        $started = microtime(true);
        $response = $this->withToken($admin->createToken('scale')->plainTextToken)
            ->getJson('/api/v1/admin/branches?per_page=100')->assertOk();
        $this->assertSame(500, $response->json('meta.total'));
        $this->assertCount(100, $response->json('data'));
        $this->assertSame(3, $response->json('data.0.employees_count'));
        $this->assertSame(2, $response->json('data.0.locations_count'));
        $ids = array_column($response->json('data'), 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->auditMetric(['case' => $this->name(), 'rows' => 500, 'wall_seconds' => microtime(true) - $started,
            'peak_bytes' => memory_get_peak_usage(true)]);
    }

    public function test_settings_read_records_the_number_of_database_queries(): void
    {
        $this->seed(SettingSeeder::class);
        $admin = User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $token = $admin->createToken('scale')->plainTextToken;
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $started = microtime(true);
            $this->withToken($token)->getJson('/api/v1/admin/settings')->assertOk()->assertJsonStructure(['data' => ['company', 'tax', 'numbering']]);
            $this->auditMetric(['case' => $this->name(), 'queries' => count(DB::getQueryLog()),
                'wall_seconds' => microtime(true) - $started, 'peak_bytes' => memory_get_peak_usage(true)]);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
