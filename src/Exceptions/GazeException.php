<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

class GazeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $exitCode,
        public readonly string $stderrHash,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $previous);
    }
}
