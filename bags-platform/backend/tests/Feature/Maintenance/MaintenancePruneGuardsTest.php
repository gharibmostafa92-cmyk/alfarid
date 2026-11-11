<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Models\CustomerAuditLog;
use App\Models\WebhookLog;
use App\Rules\UniqueCatalogIdentifier;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/** F-23/F-27/M9/L6/L7: maintenance guards and prune retention behavior. */
class MaintenancePruneGuardsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_verify_schema_passes_on_healthy_schema(): void
    {
        $this->artisan('db:verify-schema')->assertSuccessful();
    }

    public function test_verify_schema_fails_on_a_disabled_guard_trigger(): void
    {
        // F-23: a guard disabled for a data fix and never re-enabled must fail the gate.
        DB::statement('ALTER TABLE wallet_adjustments DISABLE TRIGGER wallet_adjustments_immutable');
        try {
            $this->artisan('db:verify-schema')->assertFailed();
        } finally {
            DB::statement('ALTER TABLE wallet_adjustments ENABLE TRIGGER wallet_adjustments_immutable');
        }
    }

    public function test_webhook_prune_splits_transient_and_dedup_retention(): void
    {
        // M9: non-processed rows die after --days; processed event IDs survive
        // --dedup-days; recent processed rows keep the row but lose the payload.
        $old = now()->subDays(400)->toDateTimeString();
        $recentProcessed = now()->subDays(10)->toDateTimeString();
        $oldFailed = WebhookLog::query()->create([
            'provider' => 'payment:test', 'event_id' => 'evt-old-failed',
            'payload' => ['x' => 1], 'status' => 'failed', 'ip_address' => '127.0.0.1',
        ]);
        $oldProcessed = WebhookLog::query()->create([
            'provider' => 'payment:test', 'event_id' => 'evt-old-processed',
            'payload' => ['x' => 1], 'status' => 'processed', 'ip_address' => '127.0.0.1',
        ]);
        $freshProcessed = WebhookLog::query()->create([
            'provider' => 'payment:test', 'event_id' => 'evt-fresh-processed',
            'payload' => ['x' => 1], 'status' => 'processed', 'ip_address' => '127.0.0.1',
        ]);
        foreach ([$oldFailed, $oldProcessed] as $log) {
            WebhookLog::query()->whereKey($log->id)->update(['created_at' => $old]);
        }
        WebhookLog::query()->whereKey($freshProcessed->id)->update(['created_at' => $recentProcessed]);

        $this->artisan('webhooks:prune-logs', ['--days' => 90, '--dedup-days' => 365])->assertSuccessful();

        $this->assertDatabaseMissing('webhook_logs', ['id' => $oldFailed->id]);
        $this->assertDatabaseMissing('webhook_logs', ['id' => $oldProcessed->id]);
        $this->assertDatabaseHas('webhook_logs', ['id' => $freshProcessed->id, 'payload' => null]);
    }

    public function test_staff_prune_removes_all_expired_rows_across_batches(): void
    {
        // L6: the loop must drain every expired row, however many batches that takes.
        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = [
                'actor_id' => null, 'action' => 'probe', 'target_type' => 'probe',
                'target_id' => null, 'metadata' => '{}',
                'created_at' => now()->subDays(400)->toDateTimeString(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('staff_audit_logs')->insert($chunk);
        }

        $this->artisan('staff:prune-audit-logs')->assertSuccessful();

        $this->assertSame(0, DB::table('staff_audit_logs')->where('action', 'probe')->count());
    }

    public function test_customer_audit_retention_keeps_non_auth_and_recent_rows(): void
    {
        // F-27: only the login/token auth actions older than 90 days go through
        // the definer function; everything else stays.
        $old = now()->subDays(100)->toDateTimeString();
        $expiredLogin = CustomerAuditLog::query()->create([
            'customer_id' => null, 'actor_id' => null, 'action' => 'login_failed', 'metadata' => [],
        ]);
        $expiredOther = CustomerAuditLog::query()->create([
            'customer_id' => null, 'actor_id' => null, 'action' => 'profile_updated', 'metadata' => [],
        ]);
        $freshLogin = CustomerAuditLog::query()->create([
            'customer_id' => null, 'actor_id' => null, 'action' => 'login_succeeded', 'metadata' => [],
        ]);
        CustomerAuditLog::query()->whereIn('id', [$expiredLogin->id, $expiredOther->id])->update(['created_at' => $old]);

        $removed = (int) DB::scalar('SELECT alfarid_prune_customer_audit_logs(?)', [now()->subDays(90)->toDateTimeString()], false);

        $this->assertSame(1, $removed);
        $this->assertDatabaseMissing('customer_audit_logs', ['id' => $expiredLogin->id]);
        $this->assertDatabaseHas('customer_audit_logs', ['id' => $expiredOther->id]);
        $this->assertDatabaseHas('customer_audit_logs', ['id' => $freshLogin->id]);
    }

    public function test_catalog_identifier_rejects_raw_sql_identifiers(): void
    {
        // L7: table/column interpolate into whereRaw — anything but a plain
        // identifier must be rejected at construction.
        foreach (['products; DROP TABLE users; --', 'products" OR "1"="1', 'sku) OR (1=1'] as $evil) {
            try {
                new UniqueCatalogIdentifier('products', $evil);
                $this->fail("Identifier [{$evil}] must be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertInstanceOf(UniqueCatalogIdentifier::class, new UniqueCatalogIdentifier('products', 'sku'));
    }
}
