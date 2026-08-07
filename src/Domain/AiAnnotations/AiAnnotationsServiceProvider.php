<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations;

use Illuminate\Support\ServiceProvider;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions\NotificationSideEffectSnapshot;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Contracts\AiAnnotationProvider;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Delivery\AiAnnotationOutboxDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Support\BoundedHttpsAiProvider;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Support\HarnessAiAnnotationProvider;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventDispatcher;

final class AiAnnotationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 3).'/config/ai-annotations.php', 'ai-annotations');
        $this->app->bind(AiAnnotationProvider::class, function ($app): AiAnnotationProvider {
            if ($app->environment(['testing', 'harness'])
                && (bool) config('ai-annotations.provider.harness_fake', false)) {
                return $app->make(HarnessAiAnnotationProvider::class);
            }

            return $app->make(BoundedHttpsAiProvider::class);
        });
        $this->app->extend(
            FoundationEventDispatcher::class,
            static fn (FoundationEventDispatcher $dispatcher, $app): FoundationEventDispatcher => new AiAnnotationOutboxDispatcher(
                $dispatcher,
                $app->make(NotificationSideEffectSnapshot::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/database/migrations');
        $this->loadRoutesFrom(dirname(__DIR__, 3).'/routes/ai-annotations.php');
    }
}
