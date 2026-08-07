<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MarinSolutions\CheckybotLaravel\Domain\Maintenance\Http\AuthenticateMaintenanceActor;
use MarinSolutions\CheckybotLaravel\Http\Controllers\MaintenanceModeController;

Route::middleware(['api', AuthenticateMaintenanceActor::class])
    ->prefix('/api/v1/maintenance-modes')
    ->group(static function (): void {
        Route::post('/', [MaintenanceModeController::class, 'store']);
        Route::get('/current', [MaintenanceModeController::class, 'current']);
        Route::delete('/{maintenanceMode}', [MaintenanceModeController::class, 'destroy'])
            ->whereUuid('maintenanceMode');
    });
