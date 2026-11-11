<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use Tests\TestCase;

class ResetTestingDatabaseGuardTest extends TestCase
{
    public function test_refuses_outside_local_and_testing_without_connecting(): void
    {
        $this->app['env'] = 'production';
        config()->set('database.connections.pgsql.host', 'unroutable-host-invalid');

        $this->artisan('db:reset-testing')
            ->expectsOutput('Refusing to reset: db:reset-testing runs only in local/testing environments.')
            ->assertFailed();
    }

    public function test_refuses_without_configured_password(): void
    {
        config()->set('database.connections.pgsql.host', 'unroutable-host-invalid');
        config()->set('database.connections.pgsql.password', '');

        $this->artisan('db:reset-testing')
            ->expectsOutput('Refusing to reset: database password is not configured.')
            ->assertFailed();
    }
}
