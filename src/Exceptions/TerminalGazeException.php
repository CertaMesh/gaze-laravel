<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

/**
 * Marker interface. Implementers represent failures that will not succeed on
 * a naive retry — the underlying condition needs human / config intervention
 * (wrong blob, expired session, missing binary). Queue workers should route
 * these directly to dead-letter instead of burning retry budget.
 *
 * @see TransientGazeException for retry-safe failures.
 */
interface TerminalGazeException {}
