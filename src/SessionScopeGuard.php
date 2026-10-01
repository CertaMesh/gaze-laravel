<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use CertaMesh\Gaze\Exceptions\GazePolicyConfigDetailException;

/**
 * Fail-fast pre-flight for an ephemeral session scope on `Gaze::clean()`.
 *
 * `gaze clean` always answers with an exported `session_blob` (the restore
 * contract), and upstream refuses by design to export an ephemeral session:
 * `Session::export()` returns `ExportForbidden` for `Scope::Ephemeral`, which
 * the CLI reports as the generic `{"error":"Pipeline","exit":3}`. The adapter
 * maps that to the Retryable {@see Exceptions\GazePipelineException}, so a
 * queue job configured this way retried forever (#163, #182). This guard
 * throws a NonRetryable config error before the binary is spawned instead.
 *
 * Two sources are guarded, in upstream's precedence order:
 *
 *  - the `gaze.session_scope` override ({@see assertExportable()}), which
 *    becomes `--session-scope` and wins over the policy;
 *  - with no override, the policy's `[session] scope`
 *    ({@see assertPolicyExportable()}), read via {@see PolicyFile} and cached
 *    by stat fingerprint, so the hot path costs one stat().
 *
 * The daemon path is not guarded: `gaze daemon` takes no `--session-scope`
 * and never exports a blob, so an ephemeral policy works there.
 *
 * @internal
 */
final class SessionScopeGuard
{
    public const EPHEMERAL = 'ephemeral';

    public const EPHEMERAL_UNSUPPORTED = 'gaze.session_scope=ephemeral is not supported by Gaze::clean() (pre-flight): '
        .'gaze clean must return an exportable session blob for restore(), and gaze never exports an ephemeral session. '
        .'Set GAZE_SESSION_SCOPE to conversation or persistent, or unset it to use the policy [session] scope.';

    public const POLICY_EPHEMERAL_UNSUPPORTED = 'policy [session] scope = "ephemeral" is not supported by Gaze::clean() (pre-flight): '
        .'gaze clean must return an exportable session blob for restore(), and gaze never exports an ephemeral session. '
        .'Set the policy scope to "conversation" or "persistent", or override it with GAZE_SESSION_SCOPE.';

    /**
     * Adapter-synthesized: exit bucket 2, no stderr (the binary never ran),
     * and the message carries no input text.
     *
     * @throws GazePolicyConfigDetailException
     */
    public static function assertExportable(?string $scope): void
    {
        if (self::isEphemeral($scope)) {
            throw self::refusal(self::EPHEMERAL_UNSUPPORTED);
        }
    }

    /**
     * Guard the policy's `[session] scope` when no override is set. A
     * non-empty override is forwarded as `--session-scope`, which upstream
     * applies over the policy, so the policy value is then irrelevant (an
     * ephemeral override is caught by {@see assertExportable()}).
     *
     * Matches exactly, like upstream: a policy `"Ephemeral"` is not the
     * ephemeral scope but an invalid value, which the binary rejects with its
     * own PolicyConfig detail. An unreadable or unparseable policy never
     * throws here; the binary reports PolicyOpen / PolicyConfig as before.
     *
     * @throws GazePolicyConfigDetailException
     */
    public static function assertPolicyExportable(?string $override, string $policyPath): void
    {
        if ($override !== null && $override !== '') {
            return;
        }

        if (PolicyFile::sessionScope($policyPath) === self::EPHEMERAL) {
            throw self::refusal(self::POLICY_EPHEMERAL_UNSUPPORTED);
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

    private static function refusal(string $message): GazePolicyConfigDetailException
    {
        return new GazePolicyConfigDetailException(
            $message,
            2,
            null,
            'session.scope ephemeral cannot be exported as a gaze clean session blob',
        );
    }
}
