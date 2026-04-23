<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

/**
 * Marker interface. Implementers represent failures that MAY succeed on
 * retry — timeouts, transient binary errors, load spikes. Queue workers
 * can apply normal backoff policy.
 *
 * @see TerminalGazeException for fail-fast failures.
 */
interface TransientGazeException {}
