<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Variant;

class GazeBlobExpiredException extends GazeIntegrityException
{
    public function __construct(string $message, int $exitCode, string $stderrHash, ?\Throwable $previous = null)
    {
        parent::__construct($message, $exitCode, $stderrHash, Variant::BlobExpired, $previous);
    }

    public function requiresFreshClean(): bool
    {
        return true;
    }
}
