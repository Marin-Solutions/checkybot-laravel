<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Agent\Contracts;

use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\AuthorizedLogSnippetRequest;
use MarinSolutions\CheckybotLaravel\Domain\Agent\Data\RedactedLogSnippet;

interface RedactedLogSnippetProvider
{
    public function forIncident(AuthorizedLogSnippetRequest $request): RedactedLogSnippet;
}
