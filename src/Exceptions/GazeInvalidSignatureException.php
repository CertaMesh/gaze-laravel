<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Variant;

final class GazeInvalidSignatureException extends GazeIntegrityException
{
    public function __construct(string $message, int $exitCode, string $stderrHash, ?\Throwable $previous = null)
    {
        parent::__construct($message, $exitCode, $stderrHash, Variant::InvalidSignature, $previous);
    }
}
