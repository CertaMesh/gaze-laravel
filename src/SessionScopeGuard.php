<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use CertaMesh\Gaze\Exceptions\GazePolicyConfigDetailException;

/**
 * Fail-fast pre-flight for `gaze.session_scope=ephemeral` on `Gaze::clean()`.
 *
 * `gaze clean` always answers with an exported `session_blob` (the restore
 * contract), and upstream refuses by design to export an ephemeral session:
 * `Session::export()` returns `ExportForbidden` for `Scope::Ephemeral`, which
 * the CLI reports as the generic `{"error":"Pipeline","exit":3}`. The adapter
 * maps that to the Retryable {@see Exceptions\GazePipelineException}, so a
 * queue job configured this way retried forever (#163). This guard throws a
 * NonRetryable config error before the binary is spawned instead.
 *
 * Only the `gaze.session_scope` override is guarded. A policy
 * `[session] scope = "ephemeral"` fails the same way, but the adapter does not
 * parse policy semantics at runtime; `gaze:doctor` warns about it. The daemon
 * path is not guarded: `gaze daemon` takes no `--session-scope` and never
 * exports a blob, so an ephemeral policy works there.
 *
 * @internal
 */
final class SessionScopeGuard
{
    public const EPHEMERAL = 'ephemeral';

    public const EPHEMERAL_UNSUPPORTED = 'gaze.session_scope=ephemeral is not supported by Gaze::clean() (pre-flight): '
        .'gaze clean must return an exportable session blob for restore(), and gaze never exports an ephemeral session. '
        .'Set GAZE_SESSION_SCOPE to conversation or persistent, or unset it to use the policy [session] scope.';

    /**
     * Adapter-synthesized: exit bucket 2, no stderr (the binary never ran),
     * and the message carries no input text.
     *
     * @throws GazePolicyConfigDetailException
     */
    public static function assertExportable(?string $scope): void
    {
        if (self::isEphemeral($scope)) {
            throw new GazePolicyConfigDetailException(
                self::EPHEMERAL_UNSUPPORTED,
                2,
                null,
                'session.scope ephemeral cannot be exported as a gaze clean session blob',
            );
        }
    }

    /**
     * Case- and whitespace-insensitive, so `Ephemeral` or a padded
     * `" ephemeral"` from an env file also fails fast with this message.
     */
    public static function isEphemeral(?string $scope): bool
    {
        return $scope !== null && strtolower(trim($scope)) === self::EPHEMERAL;
    }
}
