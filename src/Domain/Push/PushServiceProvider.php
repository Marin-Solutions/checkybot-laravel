<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Http\RequireLoopback;
use MarinSolutions\CheckybotLaravel\Domain\Push\Contracts\PushProjectAuthorizer;
use MarinSolutions\CheckybotLaravel\Domain\Push\Delivery\PushOutboxDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Push\Http\PushReceiptController;
use MarinSolutions\CheckybotLaravel\Domain\Push\Support\DefaultPushProjectAuthorizer;

final class PushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PushProjectAuthorizer::class, DefaultPushProjectAuthorizer::class);
        $this->app->extend(
            FoundationEventDispatcher::class,
            static fn (FoundationEventDispatcher $dispatcher): FoundationEventDispatcher => new PushOutboxDispatcher($dispatcher),
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__, 3).'/routes/push.php');
        if ($this->app->environment(['testing', 'harness'])) {
            Route::middleware(['api', RequireLoopback::class])->group(static function (): void {
                Route::get('/__harness/push/receipts/{operationId}', PushReceiptController::class)->whereUuid('operationId');
            });
        }
    }
}
