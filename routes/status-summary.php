<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http\FoundationEventController;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http\FoundationReceiptController;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http\RequireLoopback;

if (app()->environment(['testing', 'harness'])) {
    Route::middleware('api')->group(static function (): void {
        Route::post('/__harness/monitor-foundation/events', FoundationEventController::class)
            ->middleware(RequireLoopback::class);
        Route::get('/__harness/monitor-foundation/receipts/{operationId}', FoundationReceiptController::class)
            ->whereUuid('operationId')
            ->middleware(RequireLoopback::class);
    });
}
