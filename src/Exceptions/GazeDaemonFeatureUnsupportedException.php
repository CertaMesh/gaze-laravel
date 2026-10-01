<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Daemon\DaemonErrorVariant;

/**
 * Upstream binary lacks the `daemon` subcommand.
 *
 * `gaze daemon` exists in every gaze since 0.9.0 and is not behind a cargo
 * feature, so this means a binary older than 0.9.0: install the pinned one
 * (`php artisan gaze:install:binary --force`).
 */
final class GazeDaemonFeatureUnsupportedException extends GazeDaemonException
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        string $message = 'gaze daemon subcommand unavailable; the binary predates gaze 0.9.0 — run php artisan gaze:install:binary --force',
        ?string $sessionId = null,
        array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $sessionId, $raw, DaemonErrorVariant::Unavailable, $previous);
    }
}
