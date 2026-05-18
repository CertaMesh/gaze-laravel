<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Daemon\Contracts;

use Naoray\GazeLaravel\Daemon\CleanResponse;

/**
 * Stdio contract for the long-lived `gaze daemon` JSONL runtime.
 *
 * Implementations own one upstream `gaze daemon` process per instance and
 * frame requests one JSON line in, one JSON line out. Concrete client lives
 * at `Naoray\GazeLaravel\Daemon\DaemonClient`; tests substitute via the
 * `Naoray\GazeLaravel\Daemon\Contracts\DaemonClientContract` binding.
 *
 * Implementations MUST fail-closed on broken-pipe / EOF (throw
 * `GazeDaemonTransportException`) — silent reconnect masks state loss
 * because a fresh daemon echoes any `session_id` and the caller sees
 * false-positive success on a lost session.
 */
interface DaemonClientContract
{
    /**
     * Send one JSONL request and return the decoded `CleanResponse`.
     *
     * Throws `GazeDaemonException` (or a subclass) on any pipeline / transport
     * / timeout / feature-gate failure. Never returns null.
     */
    public function request(string $sessionId, string $text): CleanResponse;

    /**
     * Spawn the upstream daemon process if not already running. Idempotent.
     */
    public function connect(): void;

    /**
     * Terminate the upstream daemon process. Idempotent.
     */
    public function disconnect(): void;
}
