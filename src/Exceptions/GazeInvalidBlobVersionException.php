<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Variant;

final class GazeInvalidBlobVersionException extends GazeIntegrityException
{
    public function __construct(string $message, int $exitCode, string $stderrHash, ?\Throwable $previous = null)
    {
        parent::__construct($message, $exitCode, $stderrHash, Variant::InvalidBlobVersion, $previous);
    }

    public function requiresFreshClean(): bool
    {
        return true;
    }
}
