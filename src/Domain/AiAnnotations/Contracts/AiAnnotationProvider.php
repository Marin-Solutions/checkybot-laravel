<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Contracts;

use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderRequest;
use MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data\ProviderResult;

interface AiAnnotationProvider
{
    public function annotate(ProviderRequest $request): ProviderResult;
}
