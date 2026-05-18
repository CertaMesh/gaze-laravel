<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Daemon;

use Naoray\GazeLaravel\Daemon\Contracts\DaemonClientContract;

/**
 * Composition + hot-path entry for the daemon Facade.
 *
 *  Composition (fluent sugar):
 *      Gaze::daemon()->session($id)->clean($text);
 *
 *  Direct hot path (one PHP call = one CLI line, P5 agentic):
 *      Gaze::daemon()->clean($id, $text);
 *
 * `session($id)` is memoised per id so repeated lookups during a single
 * agent loop don't pay the DTO allocation cost. The bound client is shared
 * — sessions are addressing labels on the wire, not separate runtimes.
 */
final class DaemonManager
{
    /** @var array<string, DaemonSession> */
    private array $sessions = [];

    public function __construct(
        private readonly DaemonClientContract $client,
    ) {}

    public function session(string $id): DaemonSession
    {
        if (! isset($this->sessions[$id])) {
            $this->sessions[$id] = new DaemonSession($id, $this->client);
        }

        return $this->sessions[$id];
    }

    public function clean(string $sessionId, string $text): CleanResponse
    {
        return $this->client->request($sessionId, $text);
    }

    public function client(): DaemonClientContract
    {
        return $this->client;
    }
}
