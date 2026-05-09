<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Queue\Contracts\NonRetryable;
use Naoray\GazeLaravel\Variant;

final class GazeUnsupportedSessionScopeException extends GazeIntegrityException implements NonRetryable
{
    public function __construct(
        string $message,
        int $exitCode,
        string $stderrHash,
        private readonly string $attemptedScope,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, Variant::UnsupportedSessionScope, $previous);
    }

    public function attemptedScope(): string
    {
        return $this->attemptedScope;
    }
}
