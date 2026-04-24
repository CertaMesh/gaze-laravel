<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Variant;

final class GazeInputTooLargeException extends GazeCallerBugException
{
    public function __construct(string $message, int $exitCode, string $stderrHash, ?\Throwable $previous = null)
    {
        parent::__construct($message, $exitCode, $stderrHash, Variant::InputTooLarge, $previous);
    }
}
