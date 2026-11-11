<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteParamConstraintTest extends TestCase
{
    /** @return array<string, \Illuminate\Routing\Route> */
    private function namedRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name)) {
                $routes[$name] = $route;
            }
        }

        return $routes;
    }

    public function test_notification_read_uuid_constraint_is_consistent_everywhere(): void
    {
        $routes = $this->namedRoutes();
        $reference = $routes['admin.notifications.read']->wheres['id'] ?? null;
        $this->assertNotNull($reference);

        foreach (['pos.notifications.read', 'supplier.notifications.read', 'wholesale.notifications.read'] as $name) {
            $this->assertArrayHasKey($name, $routes, $name.' route missing');
            $this->assertSame(
                $reference,
                $routes[$name]->wheres['id'] ?? null,
                $name.' must constrain {id} exactly like admin.notifications.read'
            );
        }
    }

    public function test_numeric_admin_params_reject_non_digits_at_the_router(): void
    {
        $routes = $this->namedRoutes();
        $expected = [
            'admin.wallet.adjustments.reverse' => ['adjustment' => '[0-9]{1,18}'],
            'admin.treasury-transfers.approve' => ['treasuryTransfer' => '[0-9]{1,18}'],
            'admin.treasury-transfers.reject' => ['treasuryTransfer' => '[0-9]{1,18}'],
            'admin.ai.whatsapp.identities.link' => ['identity' => '[0-9]{1,18}'],
            'admin.ai.whatsapp.identities.revoke' => ['identity' => '[0-9]{1,18}'],
        ];

        $offenders = [];
        foreach ($expected as $name => $wheres) {
            foreach ($wheres as $param => $pattern) {
                if (($routes[$name]->wheres[$param] ?? null) !== $pattern) {
                    $offenders[] = $name.' {'.$param.'}';
                }
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, 'Unconstrained numeric params reach PostgreSQL as 22P02 500s.');
    }
}
