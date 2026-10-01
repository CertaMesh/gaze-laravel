<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Queue\Contracts\NonRetryable;
use CertaMesh\Gaze\Variant;

/**
 * @deprecated Upstream removed the `UnsupportedSessionScope` variant in gaze
 *             0.15.0 (#618). The adapter never reached it before that either:
 *             it was emitted only on the no-policy `gaze clean` path, and the
 *             adapter always passes `--policy`. An invalid `--session-scope`
 *             reports `PolicyConfig` + `detail`, i.e.
 *             {@see GazePolicyConfigDetailException}. Kept for BC; removed
 *             before 1.0.
 */
final class GazeUnsupportedSessionScopeException extends GazeIntegrityException implements NonRetryable
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
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
