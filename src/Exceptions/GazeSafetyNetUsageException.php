<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Variant;

/**
 * The binary rejected the safety-net flag combination it was given.
 *
 * Maps to upstream `CliError::SafetyNetUsageDetail` (gaze >= 0.15.0, wire
 * name `SafetyNetUsage`, exit 2): e.g. `--safety-net-backend` without exactly
 * one `--safety-net` value, `--safety-net none` combined with another
 * selection, or `--safety-net-registry` combined with `--safety-net`. The
 * argv is wrong for every input, so retrying cannot succeed — NonRetryable
 * via the `GazeOpsConfigException` ancestor, and a sibling of
 * {@see GazeSafetyNetConfigException} so existing policy/config catch blocks
 * keep matching.
 */
final class GazeSafetyNetUsageException extends GazePolicyConfigException
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        private readonly ?string $detail = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, $previous, Variant::SafetyNetUsage);
    }

    /**
     * Upstream-supplied `detail` sidecar (a fixed usage message such as
     * `"--safety-net-backend requires exactly one --safety-net value"` — never
     * input text). Null when the adapter could not decode the field.
     */
    public function detail(): ?string
    {
        return $this->detail;
    }
}
