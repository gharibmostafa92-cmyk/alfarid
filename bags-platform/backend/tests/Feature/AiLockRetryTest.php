<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\RetryableConflict;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\ToolProvider;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\WriteTool;
use App\Services\BranchAccess;
use App\Services\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * M1: a lock-timeout (55P03) escaping InventoryService::lockLevels must
 * surface as RetryableConflict — REST still sees 409, while
 * PendingActionService::confirmLocked retries instead of failing the action.
 */
class AiLockRetryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    public function test_retryable_conflict_is_a_409_but_not_a_business_exception(): void
    {
        $e = new RetryableConflict('busy');

        $this->assertSame(409, $e->getStatusCode());
        $this->assertInstanceOf(HttpExceptionInterface::class, $e);
        $this->assertNotInstanceOf(\App\Exceptions\BusinessException::class, $e);
    }

    public function test_lock_timeout_in_lock_targets_leaves_action_pending(): void
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'locations.update']);
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);

        $registry = new ToolRegistry(
            app(EntityResolver::class),
            app(BranchAccess::class),
            app(SettingService::class),
            [new LockTimeoutProvider]
        );
        $service = new PendingActionService($registry, app(SettingService::class));

        $args = ['location_id' => 1, 'active' => false];
        $action = AiPendingAction::create([
            'conversation_id' => $conv->id,
            'user_id' => $admin->id,
            'tool' => 'lock_timeout_probe',
            'args' => $args,
            'args_hash' => $registry->hashArgs($args),
            'expected_versions' => [],
            'preview' => [],
            'risk' => 'low',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        try {
            $service->confirm($admin, $action);
            $this->fail('Expected a retryable 409 conflict.');
        } catch (RetryableConflict $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame('pending', $action->fresh()->status);
    }
}

/** M1 fixture: lockTargets throws what lockLevels throws on 55P03. */
final class LockTimeoutProvider implements ToolProvider
{
    public function tools(): array
    {
        return ['lock_timeout_probe' => new LockTimeoutTool];
    }

    public function domain(): string
    {
        return 'test';
    }

    public function usesGlobalAdminLock(): bool
    {
        return false;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function canReadEntity(User $actor, string $type): ?bool
    {
        return null;
    }

    public function systemPromptSection(User $actor): ?string
    {
        return null;
    }
}

final class LockTimeoutTool implements WriteTool, LocksTargets
{
    public function name(): string
    {
        return 'lock_timeout_probe';
    }

    public function declaration(): array
    {
        return ['name' => 'lock_timeout_probe', 'description' => 'test double', 'parameters' => ['type' => 'OBJECT']];
    }

    public function permissions(): array
    {
        return ['locations.update'];
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        return ToolOutcome::claimed();
    }

    public function lockTargets(AiPendingAction $action): void
    {
        throw new RetryableConflict('المخزون مشغول بعملية أخرى الآن؛ أعد المحاولة بعد لحظات.');
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        return ToolOutcome::result(true, 'ok');
    }
}
