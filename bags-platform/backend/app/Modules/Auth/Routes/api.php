<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('admin.auth.login');

Route::middleware('auth.staff')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me'])->name('admin.auth.me');

    Route::get('/permissions', [PermissionController::class, 'index'])
        ->middleware('permission:permissions.view|roles.manage')
        ->name('admin.permissions.index');

    Route::get('/roles', [RoleController::class, 'index'])
        ->middleware('permission:roles.view|roles.manage')
        ->name('admin.roles.index');
    Route::post('/roles', [RoleController::class, 'store'])
        ->middleware('permission:roles.create|roles.manage')
        ->name('admin.roles.store');
    Route::get('/roles/{role}', [RoleController::class, 'show'])
        ->middleware('permission:roles.view|roles.manage')
        ->name('admin.roles.show');
    Route::put('/roles/{role}', [RoleController::class, 'update'])
        ->middleware('permission:roles.update|roles.manage')
        ->name('admin.roles.update');
    Route::patch('/roles/{role}', [RoleController::class, 'update'])
        ->middleware('permission:roles.update|roles.manage')
        ->name('admin.roles.patch');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('permission:roles.delete|roles.manage')
        ->name('admin.roles.destroy');
});
