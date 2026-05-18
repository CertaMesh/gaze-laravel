<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Daemon;

use Naoray\GazeLaravel\Daemon\Contracts\DaemonClientContract;

/**
 * Bound (sessionId, client) pair used by the composition Facade chain:
 *
 *     Gaze::daemon()->session('agent-thread-a')->clean('text');
 *
 * A `DaemonSession` is NOT queueable — `__serialize()` throws so adopters
 * can't accidentally hand a worker a session bound to a daemon process the
 * worker never saw. Resolve a fresh `Gaze::daemon()` per worker tick.
 */
final class DaemonSession
{
    public function __construct(
        public readonly string $sessionId,
        private readonly DaemonClientContract $client,
    ) {}

    public function id(): string
    {
        return $this->sessionId;
    }

    public function clean(string $text): CleanResponse
    {
        return $this->client->request($this->sessionId, $text);
    }

    /**
     * Serialization guard. Queueing a `DaemonSession` is undefined behaviour:
     * the bound client is process-local. Mirrored in `docs/upgrading.md` so
     * adopters know to resolve a fresh `Gaze::daemon()` per worker tick.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException(
            'DaemonSession is not serializable; resolve fresh per request via Gaze::daemon().'
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException(
            'DaemonSession is not serializable; resolve fresh per request via Gaze::daemon().'
        );
    }
}
