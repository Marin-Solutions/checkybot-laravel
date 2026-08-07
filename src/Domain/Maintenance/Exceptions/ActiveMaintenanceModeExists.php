<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Maintenance\Exceptions;

use RuntimeException;

final class ActiveMaintenanceModeExists extends RuntimeException {}
