<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Queue\Contracts\NonRetryable;
use Naoray\GazeLaravel\Variant;

final class GazeInvalidBlobVersionException extends GazeIntegrityException implements NonRetryable
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
