<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.staff')->group(function (): void {
    Route::get('/users', [UserController::class, 'index'])
        ->middleware('permission:users.view|users.manage')
        ->name('admin.users.index');
    Route::post('/users', [UserController::class, 'store'])
        ->middleware('permission:users.create|users.manage')
        ->name('admin.users.store');
    Route::get('/users/{user}', [UserController::class, 'show'])
        ->middleware('permission:users.view|users.manage')
        ->name('admin.users.show');
    Route::put('/users/{user}', [UserController::class, 'update'])
        ->middleware('permission:users.update|users.manage')
        ->name('admin.users.update');
    Route::patch('/users/{user}', [UserController::class, 'update'])
        ->middleware('permission:users.update|users.manage')
        ->name('admin.users.patch');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:users.delete|users.manage')
        ->name('admin.users.destroy');
});
