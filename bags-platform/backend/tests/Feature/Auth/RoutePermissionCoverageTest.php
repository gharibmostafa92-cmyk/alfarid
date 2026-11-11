<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoutePermissionCoverageTest extends TestCase
{
    /**
     * Financial routes that must carry route-level permission middleware as a
     * second authorization layer (controllers here use plain Request objects
     * with branch scoping only). Keys are route names, values the expected
     * permission: middleware string.
     *
     * @var array<string, string>
     */
    private const REQUIRED = [
        'admin.treasuries.index' => 'permission:treasuries.view',
        'admin.treasuries.show' => 'permission:treasuries.view',
        'admin.treasuries.store' => 'permission:treasuries.create',
        'admin.treasuries.update' => 'permission:treasuries.update',
        'admin.treasuries.destroy' => 'permission:treasuries.delete',
        'admin.expenses.index' => 'permission:expenses.view',
        'admin.expenses.show' => 'permission:expenses.view',
        'admin.expenses.attachment' => 'permission:expenses.view',
        'admin.expenses.store' => 'permission:expenses.create',
        'admin.expenses.update' => 'permission:expenses.update',
        'admin.expenses.destroy' => 'permission:expenses.delete',
        'admin.expenses.void' => 'permission:expenses.delete',
        'admin.treasury-transfers.index' => 'permission:treasury_transfers.view',
        'admin.treasury-transfers.show' => 'permission:treasury_transfers.view',
        'admin.treasury-transfers.store' => 'permission:treasury_transfers.create',
        'admin.treasury-transfers.approve' => 'permission:treasury_transfers.approve',
        'admin.treasury-transfers.reject' => 'permission:treasury_transfers.approve',
        'admin.customers.wallet.show' => 'permission:customers.wallet.view',
        'admin.customers.wallet.summary' => 'permission:customers.wallet.view',
        'admin.customers.wallet.adjustments.index' => 'permission:customers.wallet.adjust',
        'admin.customers.wallet.adjustments.store' => 'permission:customers.wallet.adjust',
        'admin.wallet.adjustments.reverse' => 'permission:customers.wallet.adjust',
        'admin.orders.update-payment' => 'permission:orders.payment',
        'admin.orders.allocate' => 'permission:orders.allocate',
        'admin.orders.deallocate' => 'permission:orders.allocate',
        'admin.returns.refund' => 'permission:returns.refund',
        'admin.payment-notices.approve' => 'permission:payment_notices.approve',
        'admin.payment-notices.reject' => 'permission:payment_notices.reject',
        'admin.supplier-payments.allocate' => 'permission:supplier_payments.update',
        'admin.supplier-payments.void' => 'permission:supplier_payments.delete',
        'admin.supplier-invoices.post' => 'permission:supplier_invoices.post',
        'admin.supplier-invoices.cancel' => 'permission:supplier_invoices.cancel',
        'admin.inventory.adjustments' => 'permission:inventory.adjust',
        'admin.stocktakes.approve' => 'permission:stocktakes.approve',
        'admin.price-lists.apply-percentage' => 'permission:price_lists.update',
        'admin.quotations.price' => 'permission:quotations.price',
        'admin.suppliers.users.index' => 'permission:suppliers.view',
        'admin.suppliers.users.store' => 'permission:suppliers.update',
        'admin.suppliers.users.update' => 'permission:suppliers.update',
        'admin.suppliers.users.destroy' => 'permission:suppliers.update',
    ];

    public function test_financial_routes_carry_route_level_permission(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name)) {
                $routes[$name] = $route;
            }
        }

        $offenders = [];
        foreach (self::REQUIRED as $name => $permission) {
            if (! isset($routes[$name])) {
                $offenders[] = $name.' (route missing)';

                continue;
            }
            $found = false;
            foreach ($routes[$name]->gatherMiddleware() as $middleware) {
                if ($middleware === $permission) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $offenders[] = $name.' (missing '.$permission.')';
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, 'Financial routes need route-level permission middleware.');
    }
}
