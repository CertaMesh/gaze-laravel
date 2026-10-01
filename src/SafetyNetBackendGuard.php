<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use CertaMesh\Gaze\Exceptions\GazeSafetyNetConfigException;

/**
 * Fail-closed pre-flight for safety-net backends upstream no longer ships.
 *
 * Upstream gaze 0.15.0 removed the Kiji DistilBERT safety net (and every
 * `--kiji-*` flag) outright. A 0.15+ binary given
 * `--safety-net-backend=kiji-distilbert` exits 2 with a bare
 * `{"error":"PolicyConfig"}` — no detail — which the adapter would surface as
 * a misleading {@see Exceptions\GazePolicyConfigException}. Both spawn paths
 * call this guard BEFORE invoking the binary instead:
 *
 *  - the one-shot `Gaze::clean()` path, and
 *  - {@see Daemon\DaemonArgv} (the `Gaze::daemon()` binding and
 *    `gaze:daemon:serve`).
 *
 * `gaze:doctor` reports the same message. `Gaze::restore()` never forwards a
 * safety-net flag and is deliberately NOT guarded, so sessions cleaned under
 * Kiji stay restorable.
 *
 * The same two spawn paths also refuse a `gaze.safety_net.nym.intra_threads`
 * that is not a positive integer ({@see self::assertNymIntraThreads()}):
 * upstream parses `--nym-intra-threads` as a non-zero integer and answers `0`
 * with the same detail-less PolicyConfig.
 */
final class SafetyNetBackendGuard
{
    /** The backend value upstream removed in gaze 0.15.0 (CertaMesh/gaze#612). */
    public const KIJI_DISTILBERT = 'kiji-distilbert';

    public const KIJI_DISTILBERT_REMOVED = 'gaze.safety_net.backend=kiji-distilbert was removed upstream in gaze 0.15.0 (pre-flight). '
        .'Switch GAZE_SAFETY_NET_BACKEND to nym (fetch the bundle with `gaze setup --safety-net nym`) '
        .'or set GAZE_SAFETY_NET=false.';

    /** The Nym backend selector (gaze >= 0.15.0); upstream matches it case-sensitively. */
    public const NYM = 'nym';

    /**
     * Every value gaze 0.15 accepts for `--safety-net-backend`. Upstream
     * parses it with clap's ValueEnum: exact and case-sensitive, so `Nym`
     * fails every clean with a detail-less PolicyConfig (exit 2).
     */
    public const ACCEPTED = ['openai-filter', self::NYM];

    /**
     * Throw when an ENABLED safety net selects a removed backend. A disabled
     * net never forwards `--safety-net-backend`, so its leftover selector is
     * inert (doctor warns about it instead).
     *
     * Adapter-synthesized: exit bucket 2, no stderr (the binary never ran),
     * and the message carries no input text.
     *
     * @throws GazeSafetyNetConfigException
     */
    public static function assertSupported(bool $enabled, ?string $backend): void
    {
        if ($enabled && self::isRemoved($backend)) {
            throw new GazeSafetyNetConfigException(self::KIJI_DISTILBERT_REMOVED, 2, null);
        }
    }

    /**
     * Throw when an enabled Nym net is given an ONNX Runtime thread count
     * that is not a positive integer: `0`, `-1`, or a value that is no
     * integer at all (`1.5`, `abc`), which the adapter would otherwise
     * truncate or drop. Only checked while the flag would be forwarded
     * ({@see GazeOptions::nymSelected()}); a value on a net that is off or
     * on another backend is inert.
     *
     * Adapter-synthesized like {@see self::assertSupported()}: exit bucket 2,
     * no stderr, no input text in the message.
     *
     * @throws GazeSafetyNetConfigException
     */
    public static function assertNymIntraThreads(GazeOptions $options): void
    {
        if (! $options->nymSelected()) {
            return;
        }

        $threads = $options->nymIntraThreads;
        $invalid = $options->invalidNymIntraThreads ?? ($threads !== null && $threads < 1 ? (string) $threads : null);

        if ($invalid !== null) {
            throw new GazeSafetyNetConfigException(
                "gaze.safety_net.nym.intra_threads must be a positive integer, got {$invalid} (pre-flight). "
                .'Unset GAZE_NYM_INTRA_THREADS to keep the upstream default of 1.',
                2,
                null,
            );
        }
    }

    /** True when gaze accepts `$backend` as is ({@see self::ACCEPTED}). */
    public static function isAccepted(string $backend): bool
    {
        return in_array($backend, self::ACCEPTED, true);
    }

    /**
     * Case- and whitespace-insensitive, so `Kiji-Distilbert` or a quoted
     * `" kiji-distilbert"` from an env file cannot slip past the pre-flight
     * into the binary's detail-less PolicyConfig error.
     */
    public static function isRemoved(?string $backend): bool
    {
        return $backend !== null && strtolower(trim($backend)) === self::KIJI_DISTILBERT;
    }
}
