<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Contracts;

use CertaMesh\Gaze\Daemon\CleanResponse;

/**
 * Bound session handle returned by `Contracts\DaemonManager::session()`.
 *
 * Implementations are NOT queueable — the bound client is process-local.
 * Resolve a fresh `Gaze::daemon()` per worker tick.
 */
interface DaemonSession
{
    /**
     * The session id this handle addresses on the daemon wire.
     */
    public function id(): string;

    /**
     * Clean $text within this session.
     */
    public function clean(string $text): CleanResponse;
}
