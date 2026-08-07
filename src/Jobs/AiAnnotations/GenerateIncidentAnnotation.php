<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Jobs\AiAnnotations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Actions\ProcessIncidentAnnotation;

final class GenerateIncidentAnnotation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A claimed operation is intentionally never retried after ambiguous provider acceptance. */
    public int $tries = 1;

    public function __construct(public readonly string $operationId) {}

    public function handle(ProcessIncidentAnnotation $processor): void
    {
        $processor->execute($this->operationId);
    }
}
