<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Queue\Contracts\NonRetryable;
use Naoray\GazeLaravel\Queue\Contracts\Retryable;
use Naoray\GazeLaravel\Queue\Contracts\RetryableWithAlert;
use Naoray\GazeLaravel\Variant;

final class GazeSafetyNetFailureException extends GazeIntegrityException implements NonRetryable, Retryable, RetryableWithAlert
{
    public function __construct(
        string $message,
        int $exitCode,
        string $stderrHash,
        private readonly string $safetyNetVariant,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, Variant::SafetyNet, $previous);
    }

    public function safetyNetVariant(): string
    {
        return $this->safetyNetVariant;
    }

    public function isRetryable(): bool
    {
        return in_array($this->safetyNetVariant, ['Timeout', 'Other'], true);
    }

    public function isRetryableWithAlert(): bool
    {
        return $this->safetyNetVariant === 'SuspectedLeak';
    }

    public function isNonRetryable(): bool
    {
        return in_array($this->safetyNetVariant, ['InputTooLarge', 'Unsupported', 'WeightsMissing'], true);
    }
}
