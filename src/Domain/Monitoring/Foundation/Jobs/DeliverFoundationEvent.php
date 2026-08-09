<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\DelayedDeliveryFake;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\DeterministicFakeEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventDispatcher;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\FoundationEventProcessor;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\RetryableFailureFake;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Delivery\TerminalFailureFake;
use MarinSolutions\CheckybotLaravel\Domain\Monitoring\Foundation\Queries\StatusSummaryQuery;
use MarinSolutions\CheckybotLaravel\Domain\Security\Foundation\RecursiveRedactor;

final class DeliverFoundationEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $operationId) {}

    public function handle(): void
    {
        $configuredFake = $this->configuredTestingFake();
        $processor = $configuredFake !== null
            ? new FoundationEventProcessor($configuredFake, RecursiveRedactor::fromConfiguration())
            : (app()->bound(FoundationEventProcessor::class)
                ? app(FoundationEventProcessor::class)
                : new FoundationEventProcessor(
                    new DeterministicFakeEventDispatcher(new StatusSummaryQuery),
                    RecursiveRedactor::fromConfiguration(),
                ));

        $processor->process($this->operationId);
    }

    private function configuredTestingFake(): ?FoundationEventDispatcher
    {
        if (! app()->environment(['testing', 'harness'])) {
            return null;
        }

        $message = (string) getenv('CHECKYBOT_FOUNDATION_DELIVERY_MESSAGE');

        return match ((string) getenv('CHECKYBOT_FOUNDATION_DELIVERY_FAKE')) {
            'retryable' => new RetryableFailureFake($message !== '' ? $message : 'Retryable fake failure'),
            'terminal' => new TerminalFailureFake($message !== '' ? $message : 'Terminal fake failure'),
            'delayed' => new DelayedDeliveryFake(max(1, (int) getenv('CHECKYBOT_FOUNDATION_DELIVERY_DELAY'))),
            default => null,
        };
    }
}
