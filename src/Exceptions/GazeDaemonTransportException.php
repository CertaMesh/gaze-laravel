<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Daemon\DaemonErrorVariant;

/**
 * Fail-closed on broken pipe / EOF / connection-lost.
 *
 * The adapter never reconnects in the hot path — a silent reconnect would
 * mask state loss, and a fresh daemon happily echoes any `session_id` so
 * the caller would see false-positive success on a lost session. Doctor's
 * `--deep` probe owns the only reconnect path.
 */
final class GazeDaemonTransportException extends GazeDaemonException
{
    public function __construct(
        string $message,
        ?string $sessionId = null,
        /** @var array<string, mixed> */
        array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $sessionId, $raw, DaemonErrorVariant::Transport, $previous);
    }
}
