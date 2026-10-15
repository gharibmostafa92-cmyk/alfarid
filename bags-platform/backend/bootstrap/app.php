<?php

use App\Http\Middleware\EnsureCustomerAuthenticated;
use App\Http\Middleware\EnsureStaffAuthenticated;
use App\Http\Middleware\EnsureSupplierAuthenticated;
use App\Http\Middleware\EnsureWholesaleApproved;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix('api/v1/admin')
                ->group(base_path('routes/api/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES');
        $middleware->trustProxies(
            at: is_string($trustedProxies) && trim($trustedProxies) !== ''
                ? array_map('trim', explode(',', $trustedProxies))
                : null,
        );

        $middleware->alias([
            'auth.staff' => EnsureStaffAuthenticated::class,
            'auth.customer' => EnsureCustomerAuthenticated::class,
            'auth.supplier' => EnsureSupplierAuthenticated::class,
            'wholesale.approved' => EnsureWholesaleApproved::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
