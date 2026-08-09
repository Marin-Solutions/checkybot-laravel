<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Http\AiAnnotationReceiptController;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Http\RequireAiAnnotationHarness;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http\RequireLoopback;
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

if (app()->environment(['testing', 'harness'])) {
    Route::middleware(['api', RequireAiAnnotationHarness::class, RequireLoopback::class])
        ->get('/__harness/ai-annotations/receipts/{operation_id}', AiAnnotationReceiptController::class)
        ->whereUuid('operation_id')
        ->name('checkybot.ai-annotations.harness.receipts.show');
}
