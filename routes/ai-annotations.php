<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MarinSolutions\CheckybotLaravel\Http\Controllers\AiAnnotationSettingsController;
use MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard\AuthenticateWebOperator;

Route::middleware(['web', AuthenticateWebOperator::class])
    ->prefix('/checkybot/ai-annotations')
    ->group(static function (): void {
        Route::get('/settings', [AiAnnotationSettingsController::class, 'show'])
            ->name('checkybot.ai-annotations.settings.show');
        Route::put('/settings', [AiAnnotationSettingsController::class, 'update'])
            ->name('checkybot.ai-annotations.settings.update');
    });
