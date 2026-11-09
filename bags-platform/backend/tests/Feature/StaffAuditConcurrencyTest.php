<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\Concerns\RunsConcurrentAuditJobs;
use Tests\TestCase;

class StaffAuditConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use RunsConcurrentAuditJobs;

    private function administrator(): User
    {
        return User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
    }

    #[TestWith(['delete'])]
    #[TestWith(['deactivate'])]
    #[TestWith(['remove_role'])]
    #[TestWith(['ai'])]
    public function test_two_administrators_cannot_remove_the_last_active_super_admin(string $mode): void
    {
        $this->seed(SettingSeeder::class);
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        $a = $this->administrator();
        $b = $this->administrator();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        $jobs = [];
        foreach ([[$a, $b], [$b, $a]] as [$actor, $target]) {
            $token = $actor->createToken('concurrency-test')->plainTextToken;
            if ($mode === 'ai') {
                $conversation = AiConversation::create(['user_id' => $actor->id, 'model' => 'test']);
                $proposal = app(ToolRegistry::class)->dispatch($actor, $conversation, ['name' => 'set_user_status', 'args' => ['user_id' => $target->id, 'active' => false]]);
                $this->assertSame('proposal', $proposal->type, $proposal->message ?? '');
                $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
                $jobs[] = $this->httpJob($token, 'POST', '/api/v1/admin/ai/actions/'.$action->id.'/confirm', ['typed_confirmation' => $target->name]);
            } else {
                $payload = ['expected_version' => (int) $target->lock_version];
                if ($mode !== 'delete') {
                    $payload += $mode === 'remove_role' ? ['roles' => []] : ['is_active' => false];
                }
                $jobs[] = $this->httpJob($token, $mode === 'delete' ? 'DELETE' : 'PUT', '/api/v1/admin/users/'.$target->id, $payload);
            }
        }
        $results = $this->concurrentJobs($jobs);
        $this->assertEqualsCanonicalizing([200, 403], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, User::where('is_active', true)->role('Super Admin', 'staff')->count());
        if ($mode === 'ai') {
            $this->assertSame(1, AiPendingAction::where('status', 'executed')->count());
        }
    }

    public function test_simultaneous_branch_edits_accept_only_one_matching_version(): void
    {
        $admin = $this->administrator();
        $branch = Branch::factory()->create();
        $token = $admin->createToken('race')->plainTextToken;
        $results = $this->concurrentJobs(array_map(fn ($name) => $this->httpJob($token, 'PUT', '/api/v1/admin/branches/'.$branch->id,
            ['name' => $name, 'lock_version' => (int) $branch->lock_version]), ['First edit', 'Second edit']));
        $this->assertEqualsCanonicalizing([200, 409], array_column($results, 'status'));
        $winner = collect($results)->firstWhere('status', 200)['body']['data']['name'];
        $this->assertSame($winner, $branch->fresh()->name);
        $this->assertSame((int) $branch->lock_version + 1, (int) $branch->fresh()->lock_version);
    }

    public function test_simultaneous_settings_updates_preserve_the_winner(): void
    {
        $this->seed(SettingSeeder::class);
        $admin = $this->administrator();
        $token = $admin->createToken('race')->plainTextToken;
        $before = app(SettingService::class)->get('company_name');
        $results = $this->concurrentJobs(array_map(fn ($name) => $this->httpJob($token, 'PUT', '/api/v1/admin/settings',
            ['company_name' => $name, 'expected' => ['company_name' => $before]]), ['Company A', 'Company B']));
        $this->assertEqualsCanonicalizing([200, 409], array_column($results, 'status'));
        $winner = collect($results)->firstWhere('status', 200)['body']['data']['company']['company_name'];
        $this->assertSame($winner, DB::table('settings')->where('key', 'company_name')->value('value'));
    }

    #[TestWith(['branch'])]
    #[TestWith(['role'])]
    #[TestWith(['email'])]
    public function test_simultaneous_duplicate_creates_return_validation_errors(string $kind): void
    {
        $admin = $this->administrator();
        $token = $admin->createToken('race')->plainTextToken;
        [$uri, $payload] = match ($kind) {
            'branch' => ['/api/v1/admin/branches', ['name' => 'Same branch', 'code' => 'CONCURRENT', 'type' => 'branch']],
            'role' => ['/api/v1/admin/roles', ['name' => 'Concurrent role', 'permissions' => []]],
            'email' => ['/api/v1/admin/users', ['name' => 'Same email', 'email' => 'concurrent@example.test', 'password' => 'ConcurrentPassword123']],
        };
        $job = $this->httpJob($token, 'POST', $uri, $payload);
        $results = $this->concurrentJobs([$job, $job]);
        $this->assertEqualsCanonicalizing([201, 422], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $count = match ($kind) {
            'branch' => Branch::where('code', 'CONCURRENT')->count(),
            'role' => Role::where('name', 'Concurrent role')->count(),
            'email' => User::where('email', 'concurrent@example.test')->count(),
        };
        $this->assertSame(1, $count);
    }
}
