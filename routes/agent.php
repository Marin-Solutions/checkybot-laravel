<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Http\AuthenticateAgentReportToken;
use MarinSolutions\CheckybotLaravel\Http\Controllers\AgentReportController;

Route::middleware(['api', AuthenticateAgentReportToken::class])
    ->post('/api/v2/agent-reports', AgentReportController::class);
