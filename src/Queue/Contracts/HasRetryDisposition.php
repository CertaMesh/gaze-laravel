<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Queue\Contracts;

use CertaMesh\Gaze\Queue\RetryAction;

/**
 * Contract for exceptions whose retry disposition depends on runtime state
 * (for example an upstream `variant` sidecar) instead of being fixed at the
 * class level.
 *
 * The static marker interfaces ({@see NonRetryable}, {@see Retryable},
 * {@see RetryableWithAlert}) encode one disposition per class and are safe to
 * branch on with `instanceof`. When a single exception class can be terminal
 * for one variant and transient for another, implement this contract instead —
 * `GazeRetryPolicy::classify()` consults it before any marker interface.
 */
interface HasRetryDisposition
{
    public function retryDisposition(): RetryAction;
}
