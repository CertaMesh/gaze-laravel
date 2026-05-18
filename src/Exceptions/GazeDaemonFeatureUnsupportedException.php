<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Daemon\DaemonErrorVariant;

/**
 * Upstream binary lacks the `daemon` subcommand.
 *
 * The GitHub-release binary may be built without `--features daemon`.
 * Doctor's pre-flight surfaces this exception verbatim with the hint:
 *
 *     cargo install gaze-cli --features daemon
 */
final class GazeDaemonFeatureUnsupportedException extends GazeDaemonException
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        string $message = 'gaze daemon subcommand unavailable; rebuild with: cargo install gaze-cli --features daemon',
        ?string $sessionId = null,
        array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $sessionId, $raw, DaemonErrorVariant::Unavailable, $previous);
    }
}
