<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Alerting\Contracts;

interface MonitorResultIngestionInterface
{
    public function ingest(NormalizedMonitorResult $result): IngestionReceipt;
}
