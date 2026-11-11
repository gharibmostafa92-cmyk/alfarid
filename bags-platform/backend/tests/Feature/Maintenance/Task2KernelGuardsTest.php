<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Models\Setting;
use App\Models\User;
use App\Services\Shared\OperationIdempotencyService;
use App\Services\Shared\SettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

/** task2 section 1: maintenance + numbering + idempotency guards. */
class Task2KernelGuardsTest extends TestCase
{
    public function test_whatsapp_watch_alerts_for_any_non_connected_state_after_ten_minutes(): void
    {
        config()->set('services.whatsapp_gateway.url', 'http://wa.test');
        config()->set('services.whatsapp_gateway.secret', 'secret');
        config()->set('logging.channels.slack.url', null);
        Cache::flush();
        Cache::put('wa-gateway-disconnected-since', now()->subMinutes(15)->toIso8601String(), now()->addDay());
        Http::fake(['*/status' => Http::response(['state' => 'qr'], 200)]);
        Log::spy();

        $this->artisan('whatsapp:watch-connection')->assertSuccessful();

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_numbering_collision_scan_uses_a_bounded_sql_max(): void
    {
        DB::statement('CREATE TEMPORARY TABLE t2_numbering_scan (number varchar(50))');
        try {
            DB::table('t2_numbering_scan')->insert([
                ['number' => 'ORD-0001'],
                ['number' => 'ORD-0002'],
                ['number' => 'ORD-XYZ'],
                ['number' => 'ORD-999999999999999999999999'],
                ['number' => 'OTHER-0009'],
            ]);

            $method = new ReflectionMethod(SettingService::class, 'maxNumericSuffix');
            $max = $method->invoke(null, 't2_numbering_scan', 'number', 'ORD-');

            $this->assertSame(2, $max);
            $this->assertNull($method->invoke(null, 't2_numbering_scan', 'number', 'MISSING-'));
        } finally {
            DB::statement('DROP TABLE pg_temp.t2_numbering_scan');
        }
    }

    public function test_static_settings_request_cache_invalidates_when_the_version_changes(): void
    {
        $key = 'task2_cache_probe_'.Str::lower(Str::random(12));
        Setting::query()->create(['group' => 'test', 'key' => $key, 'value' => 'first', 'type' => 'string']);
        Cache::flush();

        try {
            $service = app(SettingService::class);
            $this->assertSame('first', $service->get($key));

            // Simulate another process updating settings and bumping the version.
            Setting::query()->where('key', $key)->update(['value' => 'second']);
            Cache::forever('app_settings_static_version', (string) Str::uuid());

            $this->assertSame('second', $service->get($key));
        } finally {
            Setting::query()->where('key', $key)->delete();
        }
    }

    public function test_idempotency_rejects_an_unsaved_result_before_persisting_a_null_id(): void
    {
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE operation_idempotency_keys (
                operation varchar(255), actor_type varchar(255), actor_id bigint,
                key varchar(128), request_hash varchar(64), created_at timestamp,
                result_type varchar(255), result_id bigint,
                UNIQUE (operation, actor_type, actor_id, key)
            )
            SQL);

        $actor = new User;
        $actor->id = 1;

        try {
            $this->expectException(LogicException::class);
            app(OperationIdempotencyService::class)->run(
                'task2-unsaved',
                $actor,
                'unsaved-result',
                [],
                static fn (): User => new User,
            );
        } finally {
            $this->assertSame(0, DB::table('operation_idempotency_keys')->count());
            DB::statement('DROP TABLE pg_temp.operation_idempotency_keys');
        }
    }
}
