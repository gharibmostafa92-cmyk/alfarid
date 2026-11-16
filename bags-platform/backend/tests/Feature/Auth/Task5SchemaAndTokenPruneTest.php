<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\User;
use App\Services\Auth\StaffTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Task5SchemaAndTokenPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_audit_prune_function_enforces_search_path_and_90_day_floor(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific security test.');
        }

        $proc = DB::selectOne("
            SELECT proconfig
            FROM pg_proc
            WHERE proname = 'alfarid_prune_customer_audit_logs'
            LIMIT 1
        ");

        $this->assertNotNull($proc);
        $config = (string) ($proc->proconfig ?? '');
        $this->assertStringContainsString('pg_catalog', $config);
        $this->assertStringContainsString('pg_temp', $config);

        $customer = Customer::factory()->create();

        $recentId = DB::table('customer_audit_logs')->insertGetId([
            'customer_id' => $customer->id,
            'action' => 'login_failed',
            'created_at' => now()->subDays(10),
        ]);

        $oldId = DB::table('customer_audit_logs')->insertGetId([
            'customer_id' => $customer->id,
            'action' => 'login_failed',
            'created_at' => now()->subDays(100),
        ]);

        $removed = (int) DB::scalar("SELECT alfarid_prune_customer_audit_logs('infinity'::timestamptz)");

        $this->assertSame(1, $removed);
        $this->assertDatabaseHas('customer_audit_logs', ['id' => $recentId]);
        $this->assertDatabaseMissing('customer_audit_logs', ['id' => $oldId]);

        $hasAppRole = (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'alfarid_app')");
        if ($hasAppRole) {
            $canTruncate = (bool) DB::scalar("SELECT has_table_privilege('alfarid_app', 'public.customer_audit_logs', 'TRUNCATE')");
            $this->assertFalse($canTruncate, 'alfarid_app must not have TRUNCATE on customer_audit_logs');
        }
    }

    public function test_personal_access_tokens_has_composite_prune_index_and_prune_and_cap_keeps_newest(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $index = DB::selectOne("
                SELECT indexdef
                FROM pg_indexes
                WHERE schemaname = 'public'
                  AND tablename = 'personal_access_tokens'
                  AND indexname = 'personal_access_tokens_tokenable_id_desc_idx'
            ");
            $this->assertNotNull($index, 'Expected composite index personal_access_tokens_tokenable_id_desc_idx on personal_access_tokens.');
        }

        $user = User::factory()->create();
        $t1 = $user->createToken('staff-token', ['*'], now()->addHour())->accessToken->id;
        $t2 = $user->createToken('staff-token', ['*'], now()->addHour())->accessToken->id;
        $t3 = $user->createToken('staff-token', ['*'], now()->addHour())->accessToken->id;
        $t4 = $user->createToken('staff-token', ['*'], now()->addHour())->accessToken->id;
        $expired = $user->createToken('staff-token', ['*'], now()->subMinute())->accessToken->id;

        StaffTokenService::pruneAndCap($user, 3);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $expired]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $t1]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $t2]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $t3]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $t4]);
    }
}
