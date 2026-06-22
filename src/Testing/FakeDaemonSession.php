<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\Daemon\DaemonSession;

/**
 * Fake DaemonSession used by `Gaze::fake()` so adopter test code can
 * assert daemon usage without spawning a real binary.
 *
 * Calls flow back to `FakeDaemonManager` (which holds the call recorder)
 * via the bound back-reference — that keeps the call log centralised on
 * the manager regardless of which entry point the test exercises.
 *
 * Extends `DaemonSession` so it honours the parent `session(): DaemonSession`
 * return-type contract. The parent constructor is bypassed because the
 * fake never resolves a real client.
 */
final class FakeDaemonSession extends DaemonSession
{
    public function __construct(
        public readonly string $sessionId,
        private readonly FakeDaemonManager $manager,
    ) {
        // Deliberately skip parent ctor — fake holds no client.
    }

    public function id(): string
    {
        return $this->sessionId;
    }

    public function clean(string $text): CleanResponse
    {
        return $this->manager->clean($this->sessionId, $text);
    }
}
