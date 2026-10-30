<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Shared\NotificationController;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskMaintenanceChecklistTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_notification_retention_keeps_exact_boundaries_and_clamps_unread_days(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $rows = [];
        foreach ([
            'read_old' => [91, true], 'read_boundary' => [90, true], 'read_recent' => [89, true],
            'unread_old' => [366, false], 'unread_boundary' => [365, false], 'unread_recent' => [364, false],
        ] as $name => [$days, $read]) {
            $rows[$name] = $this->notification($user, now()->subDays($days), $read);
        }
        DB::table('notifications')->insert(array_values($rows));
        $this->artisan('notifications:prune-read')->assertSuccessful();
        foreach ($rows as $name => $row) {
            $this->assertSame(! str_ends_with($name, '_old'), DB::table('notifications')->where('id', $row['id'])->exists(), $name);
        }
        $this->artisan('notifications:prune-read --days=90 --unread-days=1')->assertSuccessful();
        $this->assertSame(2, DB::table('notifications')->count());
        $this->assertDatabaseHas('notifications', ['id' => $rows['read_boundary']['id']]);
    }

    public function test_read_all_handles_2500_rows_and_preserves_new_arrivals_and_other_morph_types(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['id' => $user->id]);
        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = $this->notification($user, now()->subDay());
        }
        foreach (array_chunk($rows, 250) as $batch) {
            DB::table('notifications')->insert($batch);
        }
        DB::table('notifications')->insert($this->notification($customer, now()->subDay()));
        $arrived = false;
        DB::listen(function ($query) use ($user, &$arrived): void {
            if (! $arrived && str_starts_with($query->sql, 'update "notifications"')) {
                $arrived = true;
                DB::table('notifications')->insert($this->notification($user, now()->addSecond()));
            }
        });
        $request = Request::create('/notifications/read-all', 'POST');
        $request->setUserResolver(fn () => $user);
        $response = app(NotificationController::class)->readAll($request);
        $this->assertTrue($arrived);
        $this->assertSame(2500, $user->readNotifications()->count());
        $this->assertSame(1, $user->unreadNotifications()->count());
        $this->assertSame(1, $customer->unreadNotifications()->count());
        $this->assertSame(1, $response->getData(true)['meta']['unread_count']);
    }

    #[DataProvider('idempotencyRetention')]
    public function test_idempotency_cleanup_spans_multiple_batches_and_keeps_the_boundary(string $table, string $command, int $days): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $base = $table === 'operation_idempotency_keys'
            ? ['operation' => 'checklist', 'actor_type' => $user->getMorphClass(), 'actor_id' => $user->id]
            : ['user_id' => $user->id];
        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = [...$base, 'key' => 'old-'.$i, 'request_hash' => str_repeat('a', 64), 'created_at' => now()->subDays($days)->subSecond()];
        }
        foreach (array_chunk($rows, 250) as $batch) {
            DB::table($table)->insert($batch);
        }
        DB::table($table)->insert([...$base, 'key' => 'boundary', 'request_hash' => str_repeat('b', 64), 'created_at' => now()->subDays($days)]);
        $this->artisan($command)->assertSuccessful();
        $this->assertDatabaseCount($table, 1);
        $this->assertDatabaseHas($table, ['key' => 'boundary']);
    }

    public static function idempotencyRetention(): array
    {
        return [['operation_idempotency_keys', 'operations:prune-idempotency-keys', 90], ['inventory_idempotency_keys', 'inventory:prune-idempotency-keys', 7]];
    }

    public function test_webhook_retention_keeps_processed_deduplication_keys_but_scrubs_payloads(): void
    {
        $this->freezeTime();
        $rows = [];
        foreach (['processed', 'received', 'duplicate', 'ignored', 'failed'] as $status) {
            $rows[] = ['provider' => 'checklist', 'event_id' => $status, 'status' => $status, 'payload' => '{"private":"data"}', 'created_at' => now()->subDays(91), 'updated_at' => now()];
        }
        $rows[] = ['provider' => 'checklist', 'event_id' => 'boundary', 'status' => 'failed', 'payload' => '{}', 'created_at' => now()->subDays(90), 'updated_at' => now()];
        DB::table('webhook_logs')->insert($rows);
        $this->artisan('webhooks:prune-logs')->assertSuccessful();
        $this->assertDatabaseCount('webhook_logs', 2);
        $this->assertDatabaseHas('webhook_logs', ['provider' => 'checklist', 'event_id' => 'processed', 'payload' => null]);
        $this->assertSame('{}', DB::table('webhook_logs')->where('event_id', 'boundary')->value('payload'));
        $this->artisan('webhooks:prune-logs')->assertSuccessful();
        $this->assertDatabaseCount('webhook_logs', 2);
    }

    public function test_private_attachment_json_lookups_can_use_the_gin_index(): void
    {
        // Disable sequential scans only to check index eligibility, not timing.
        DB::statement('SET LOCAL enable_seqscan = off');
        $query = DB::table('return_request_items')->select('id')->whereJsonContains('attachments', 'return_requests/checklist/proof.jpg');
        $plan = DB::selectOne('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());
        $this->assertStringContainsString('return_request_items_attachments_gin_idx', implode('', (array) $plan));
    }

    #[DataProvider('invalidMaintenanceOptions')]
    public function test_maintenance_commands_reject_unsafe_options(string $command, int $exit): void
    {
        $this->artisan($command)->assertExitCode($exit);
    }

    public static function invalidMaintenanceOptions(): array
    {
        return [
            ['attachments:prune-orphans --hours=23', 1],
            ['media:prune-orphans --older-than=59', 1],
            ['media:prune-orphans --disk=other', 1],
            ['inventory:reconcile --limit=0', 2],
            ['inventory:reconcile --limit=10001', 2],
            ['inventory:reconcile --branch_id=0', 2],
        ];
    }

    public function test_every_scheduled_event_has_a_finite_overlap_lock_and_single_server_guard(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping, $event->description ?? $event->command);
            $this->assertTrue($event->onOneServer);
            $this->assertGreaterThan(0, $event->expiresAt);
            $this->assertLessThan(1440, $event->expiresAt);
            if (preg_match('/^\d+ \d+ \* \* \*$/', $event->expression)) {
                $this->assertSame('Africa/Cairo', $event->timezone);
            }
        }
        $this->artisan('schedule:list')->assertSuccessful();
    }

    private function notification(User|Customer $recipient, mixed $createdAt, bool $read = false): array
    {
        return ['id' => (string) Str::uuid(), 'type' => 'checklist', 'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->id, 'data' => '{}', 'read_at' => $read ? now() : null,
            'created_at' => $createdAt, 'updated_at' => $createdAt];
    }
}
