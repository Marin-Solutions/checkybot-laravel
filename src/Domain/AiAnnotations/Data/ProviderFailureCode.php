<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\AiAnnotations\Data;

enum ProviderFailureCode: string
{
    case NotConfigured = 'not_configured';
    case Timeout = 'timeout';
    case Transport = 'transport';
    case RateLimited = 'rate_limited';
    case InvalidResponse = 'invalid_response';
    case OverReservation = 'over_reservation';
}
