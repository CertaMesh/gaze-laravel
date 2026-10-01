<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition;
use CertaMesh\Gaze\Queue\RetryAction;
use CertaMesh\Gaze\Queue\SafetyNetRetryMap;
use CertaMesh\Gaze\Variant;

/**
 * The retry disposition of a safety-net failure depends on the upstream
 * `variant` sidecar, so this exception deliberately implements NONE of the
 * static marker interfaces (NonRetryable / Retryable / RetryableWithAlert).
 * Branch on {@see self::retryDisposition()} or `GazeRetryPolicy::classify()`.
 *
 * The variant → disposition map lives in {@see SafetyNetRetryMap}, shared
 * with the daemon's `SafetyNet*` errors.
 */
final class GazeSafetyNetFailureException extends GazeIntegrityException implements HasRetryDisposition
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
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
        return $this->retryDisposition() === RetryAction::ReleaseWithBackoff;
    }

    public function isRetryableWithAlert(): bool
    {
        return $this->retryDisposition() === RetryAction::ReleaseWithAlert;
    }

    /**
     * True only for variants explicitly mapped to `Fail`. An unknown variant
     * also fails (closed), but answers false here.
     */
    public function isNonRetryable(): bool
    {
        return SafetyNetRetryMap::knows($this->safetyNetVariant)
            && $this->retryDisposition() === RetryAction::Fail;
    }

    /**
     * Unknown variants (upstream may add new ones) fail closed: anything not
     * explicitly retryable maps to RetryAction::Fail.
     */
    public function retryDisposition(): RetryAction
    {
        return SafetyNetRetryMap::for($this->safetyNetVariant);
    }
}
