<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task3SecurityCloseoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_global_login_failures_demand_a_challenge_before_another_password_check(): void
    {
        config([
            'auth.unified_login_global_failures_minute' => 1,
            'services.turnstile.secret_key' => 'test-secret',
        ]);
        Http::fake(['https://challenges.cloudflare.com/*' => Http::response(['success' => false, 'hostname' => 'alfarid.test'])]);

        $this->postJson('/api/v1/auth/login', ['login' => 'nobody-a@example.test', 'password' => 'wrong-password-1'])
            ->assertUnauthorized();
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['login' => 'nobody-b@example.test', 'password' => 'wrong-password-2'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'challenge_required');
        Http::assertNothingSent();
    }

    public function test_app_role_cannot_rewrite_audit_logs_and_prune_uses_the_definer(): void
    {
        $grants = collect(DB::select("SELECT privilege_type FROM information_schema.role_table_grants WHERE grantee = 'alfarid_app' AND table_name = 'staff_audit_logs'"))
            ->pluck('privilege_type');
        $this->assertTrue($grants->contains('INSERT'));
        $this->assertFalse($grants->contains('UPDATE'));
        $this->assertFalse($grants->contains('DELETE'));

        DB::table('staff_audit_logs')->insert([
            'actor_id' => null,
            'action' => 'old.entry',
            'target_type' => 'user',
            'target_id' => 1,
            'metadata' => '{}',
            'created_at' => now()->subDays(40),
        ]);
        $this->assertSame(1, (int) DB::scalar('SELECT alfarid_prune_staff_audit_logs(?)', [now()->subDays(30)]));
        $this->assertDatabaseMissing('staff_audit_logs', ['action' => 'old.entry']);
    }

    public function test_legacy_staff_login_uses_the_unified_limits_and_pos_abilities(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create([
            'password' => 'Cashier-password12',
            'branch_id' => $branch->id,
        ]);
        $cashier->assignRole(Role::findOrCreate('cashier', 'staff'));

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $cashier->email,
            'password' => 'Cashier-password12',
        ])->assertOk();

        $this->assertSame(['pos'], $cashier->tokens()->sole()->abilities);
    }
}
