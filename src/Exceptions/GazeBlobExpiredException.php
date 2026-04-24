<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Queue\Contracts\NonRetryable;
use Naoray\GazeLaravel\Variant;

class GazeBlobExpiredException extends GazeIntegrityException implements NonRetryable
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
