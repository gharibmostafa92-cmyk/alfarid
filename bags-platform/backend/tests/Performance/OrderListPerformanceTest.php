<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Opt-in benchmark against the disposable PostgreSQL test database. */
class OrderListPerformanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_search_date_filter_and_stats_on_one_million_orders(): void
    {
        $this->assertSame('alfarid_testing', DB::connection()->getDatabaseName());
        config(['app.business_timezone' => 'Africa/Cairo']);
        $staff = User::factory()->create();
        $staff->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $token = $staff->createToken('order-benchmark')->plainTextToken;
        $started = hrtime(true);
        DB::statement(<<<'SQL'
INSERT INTO orders (order_number, guest_name, status, payment_method, created_at, updated_at)
SELECT 'PERF-' || n, CASE WHEN n = 999999 THEN 'Distinct Benchmark Customer' ELSE 'Customer ' || n END,
       CASE WHEN n % 2 = 0 THEN 'pending' ELSE 'processing' END,
       'cod', TIMESTAMP '2026-09-01 09:00:00' + (n % 100) * INTERVAL '1 day',
       TIMESTAMP '2026-12-10 09:00:00'
FROM generate_series(1, 1000000) AS n
SQL);
        DB::statement('ANALYZE orders');
        $metrics = ['orders' => 1_000_000, 'setup_ms' => round((hrtime(true) - $started) / 1e6, 2)];
        $base = '/api/v1/admin/orders';
        $this->withToken($token)->getJson($base)->assertOk();

        foreach ([
            'number' => "{$base}?search=PERF-999999",
            'name' => "{$base}?search=Distinct%20Benchmark%20Customer",
            'date' => "{$base}?date_from=2026-09-01&date_to=2026-09-01",
            'stats' => "{$base}/stats",
        ] as $case => $path) {
            Auth::forgetGuards();
            $started = hrtime(true);
            $response = $this->withToken($token)->getJson($path);
            $metrics[$case.'_ms'] = round((hrtime(true) - $started) / 1e6, 2);
            $response->assertOk();
            match ($case) {
                'number', 'name' => $response->assertJsonPath('meta.total', 1)
                    ->assertJsonPath('data.0.order_number', 'PERF-999999'),
                'date' => $response->assertJsonPath('meta.total', 10_000),
                'stats' => $response->assertJsonPath('data.pending', 500_000)
                    ->assertJsonPath('data.processing', 500_000),
            };
        }

        file_put_contents(storage_path('logs/order-list-performance.json'), json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
