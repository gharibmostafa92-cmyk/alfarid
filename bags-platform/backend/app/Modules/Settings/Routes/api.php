<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\SettingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.staff')->group(function (): void {
    Route::get('/settings', [SettingController::class, 'index'])
        ->middleware('permission:settings.view|settings.manage')
        ->name('admin.settings.index');
    Route::put('/settings', [SettingController::class, 'update'])
        ->middleware('permission:settings.update|settings.manage')
        ->name('admin.settings.update');
});
