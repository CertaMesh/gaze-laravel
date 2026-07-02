<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use CertaMesh\Gaze\Contracts\DaemonSession as DaemonSessionContract;
use CertaMesh\Gaze\Daemon\CleanResponse;

/**
 * Fake DaemonSession used by `Gaze::fake()` so adopter test code can
 * assert daemon usage without spawning a real binary.
 *
 * Calls flow back to `FakeDaemonManager` (which holds the call recorder)
 * via the bound back-reference — that keeps the call log centralised on
 * the manager regardless of which entry point the test exercises.
 *
 * Implements `Contracts\DaemonSession` directly (no longer extends the
 * concrete `DaemonSession`) and mirrors its non-serializable guard.
 */
final class FakeDaemonSession implements DaemonSessionContract
{
    public function __construct(
        public readonly string $sessionId,
        private readonly FakeDaemonManager $manager,
    ) {}

    public function id(): string
    {
        return $this->sessionId;
    }

    public function clean(string $text): CleanResponse
    {
        return $this->manager->clean($this->sessionId, $text);
    }

    /**
     * Serialization guard, mirroring the real `DaemonSession`: queueing a
     * session handle is undefined behaviour. Resolve a fresh
     * `Gaze::daemon()` per worker tick.
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
