<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Queue\Contracts\NonRetryable;
use Naoray\GazeLaravel\Variant;

class GazeIntegrityException extends GazeException implements NonRetryable
{
    public function __construct(
        string $message,
        int $exitCode,
        string $stderrHash,
        ?Variant $variant = Variant::UnknownToken,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, $variant, $previous);
    }

    public function requiresFreshClean(): bool
    {
        return false;
    }
}
