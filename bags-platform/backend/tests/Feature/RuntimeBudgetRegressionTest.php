<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RefreshCatalogRetailPrices;
use App\Services\StatementBudget;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RuntimeBudgetRegressionTest extends TestCase
{
    #[DataProvider('transientStates')]
    public function test_transient_database_failures_return_generic_retryable_responses(string $state): void
    {
        Route::get('/api/runtime-budget-regression', function () use ($state): never {
            $cause = new PDOException('private database detail');
            $cause->errorInfo = [$state];

            throw new QueryException('pgsql', 'SELECT confidential_field FROM customers', [], $cause);
        });

        $this->getJson('/api/runtime-budget-regression')->assertStatus(503)->assertHeader('Retry-After', '1')
            ->assertDontSee('confidential_field')->assertDontSee('private database detail');
    }

    public static function transientStates(): array
    {
        return [['57014'], ['08006'], ['08001']];
    }

    public function test_default_queue_visibility_exceeds_the_longest_job_timeout(): void
    {
        $job = new RefreshCatalogRetailPrices;
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertTrue($job->failOnTimeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
        $this->assertSame(0, config('database.redis.health.max_retries'));
        $this->assertLessThanOrEqual(0.25, config('database.redis.health.timeout'));
    }

    public function test_statement_budget_is_scoped_and_restored_on_success_and_nested_rollback(): void
    {
        DB::transaction(function (): void {
            $before = DB::selectOne("SELECT current_setting('statement_timeout') AS budget")->budget;
            $during = StatementBudget::run(150000, fn () => DB::selectOne("SELECT current_setting('statement_timeout') AS budget")->budget);
            $this->assertSame('150s', $during);
            $this->assertSame($before, DB::selectOne("SELECT current_setting('statement_timeout') AS budget")->budget);
            try {
                StatementBudget::run(100, function (): never {
                    throw new RuntimeException('abort budget');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('abort budget', $exception->getMessage());
            }
            $this->assertSame($before, DB::selectOne("SELECT current_setting('statement_timeout') AS budget")->budget);
        });
    }
}
