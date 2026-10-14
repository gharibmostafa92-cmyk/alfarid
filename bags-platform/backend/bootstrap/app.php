<?php

use App\Http\Middleware\EnsureCustomerAuthenticated;
use App\Http\Middleware\EnsureStaffAuthenticated;
use App\Http\Middleware\EnsureSupplierAuthenticated;
use App\Http\Middleware\EnsureWholesaleApproved;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
