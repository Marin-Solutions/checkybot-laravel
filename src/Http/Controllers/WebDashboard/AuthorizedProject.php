<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Http\Controllers\WebDashboard;

final readonly class AuthorizedProject
{
    public function __construct(public string $uuid) {}
}
