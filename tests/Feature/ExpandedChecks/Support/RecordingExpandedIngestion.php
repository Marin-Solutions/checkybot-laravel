<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Tests\Feature\ExpandedChecks\Support;

use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\IngestionReceipt;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\MonitorResultIngestionInterface;
use MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts\NormalizedMonitorResult;

final class RecordingExpandedIngestion implements MonitorResultIngestionInterface
{
    /** @var list<NormalizedMonitorResult> */
    public array $results = [];

    public function ingest(NormalizedMonitorResult $result): IngestionReceipt
    {
        $this->results[] = $result;

        return new IngestionReceipt(true, $result->operationId, 'queued');
    }
}
