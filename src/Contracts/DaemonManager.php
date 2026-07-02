<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Contracts;

use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\Daemon\Contracts\DaemonClientContract;

/**
 * Composition + hot-path contract for the `Gaze::daemon()` chain.
 *
 *  Composition (fluent sugar):
 *      Gaze::daemon()->session($id)->clean($text);
 *
 *  Direct hot path (one PHP call = one CLI line):
 *      Gaze::daemon()->clean($id, $text);
 */
interface DaemonManager
{
    /**
     * Resolve the (memoised per id) session handle for the given session id.
     */
    public function session(string $id): DaemonSession;

    /**
     * One-shot hot path: clean $text within $sessionId.
     */
    public function clean(string $sessionId, string $text): CleanResponse;

    /**
     * The underlying daemon stdio client.
     */
    public function client(): DaemonClientContract;
}
