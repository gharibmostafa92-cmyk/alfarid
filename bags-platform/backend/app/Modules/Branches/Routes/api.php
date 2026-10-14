<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\LocationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.staff')->group(function (): void {
    Route::get('/branches', [BranchController::class, 'index'])
        ->middleware('permission:branches.view|branches.manage');
    Route::post('/branches', [BranchController::class, 'store'])
        ->middleware('permission:branches.create|branches.manage');
    Route::get('/branches/{branch}', [BranchController::class, 'show'])
        ->middleware('permission:branches.view|branches.manage');
    Route::match(['put', 'patch'], '/branches/{branch}', [BranchController::class, 'update'])
        ->middleware('permission:branches.update|branches.manage');
    Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])
        ->middleware('permission:branches.delete|branches.manage');

    Route::scopeBindings()->group(function (): void {
        Route::get('/branches/{branch}/locations', [LocationController::class, 'index'])
            ->middleware('permission:locations.view|locations.manage');
        Route::post('/branches/{branch}/locations', [LocationController::class, 'store'])
            ->middleware('permission:locations.create|locations.manage');
        Route::get('/branches/{branch}/locations/{location}', [LocationController::class, 'show'])
            ->middleware('permission:locations.view|locations.manage');
        Route::match(['put', 'patch'], '/branches/{branch}/locations/{location}', [LocationController::class, 'update'])
            ->middleware('permission:locations.update|locations.manage');
        Route::delete('/branches/{branch}/locations/{location}', [LocationController::class, 'destroy'])
            ->middleware('permission:locations.delete|locations.manage');
    });
});
