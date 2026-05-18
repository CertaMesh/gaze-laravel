<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Daemon\DaemonErrorVariant;

/**
 * Per-request timeout exceeded for `gaze daemon` JSONL round-trip.
 *
 * Distinct catch-arm so queue back-pressure / circuit-breakers can react
 * differently than for fail-closed transport faults.
 */
final class GazeDaemonTimeoutException extends GazeDaemonException
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        string $message,
        ?string $sessionId = null,
        array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $sessionId, $raw, DaemonErrorVariant::Timeout, $previous);
    }
}
