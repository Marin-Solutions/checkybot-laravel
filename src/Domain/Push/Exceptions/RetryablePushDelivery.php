<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Exceptions;

use RuntimeException;

final class RetryablePushDelivery extends RuntimeException {}
