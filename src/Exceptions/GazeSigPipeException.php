<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

class GazeSigPipeException extends GazeInfraException
{
    public function __construct(string $message, int $exitCode, string $stderrHash, ?\Throwable $previous = null)
    {
        parent::__construct($message, $exitCode, $stderrHash, null, $previous);
    }
}
