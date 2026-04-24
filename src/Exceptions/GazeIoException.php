<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Queue\Contracts\RetryableWithAlert;
use Naoray\GazeLaravel\Variant;

class GazeIoException extends GazeInfraException implements RetryableWithAlert
{
    public function __construct(string $message, int $exitCode, string $stderrHash, ?\Throwable $previous = null)
    {
        parent::__construct($message, $exitCode, $stderrHash, Variant::Io, $previous);
    }
}
