<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http\RequireLoopback;
use MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard\AuthenticateWebOperator;
use MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard\HarnessWebOperatorController;
use MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard\MonitorDetailController;
use MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard\OverviewController;

Route::middleware(['web', AuthenticateWebOperator::class])
    ->prefix('/checkybot')
    ->group(static function (): void {
        Route::get('/', OverviewController::class)->name('checkybot.dashboard');
        Route::get('/monitors/{type}/{monitor_uuid}', MonitorDetailController::class)
            ->whereIn('type', ['server', 'website', 'api'])
            ->whereUuid('monitor_uuid')
            ->name('checkybot.monitors.show');
    });

if (app()->environment(['testing', 'harness'])) {
    Route::middleware(['web', RequireLoopback::class])
        ->get('/__harness/web-dashboard/authenticate/{projectUuid}', HarnessWebOperatorController::class)
        ->whereUuid('projectUuid');
}
