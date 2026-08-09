<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MarinSolutions\CheckybotLaravel\Domain\Push\Http\AuthenticatePushUser;
use MarinSolutions\CheckybotLaravel\Http\Controllers\PushDeviceController;

Route::middleware(['api', AuthenticatePushUser::class])
    ->prefix('/api/v1/push-devices')
    ->group(static function (): void {
        Route::post('/', [PushDeviceController::class, 'store']);
        Route::delete('/{pushDevice}', [PushDeviceController::class, 'destroy'])->whereUuid('pushDevice');
    });
