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
 */
final class SafetyNetBackendGuard
{
    /** The backend value upstream removed in gaze 0.15.0 (CertaMesh/gaze#612). */
    public const KIJI_DISTILBERT = 'kiji-distilbert';

    public const KIJI_DISTILBERT_REMOVED = 'gaze.safety_net.backend=kiji-distilbert was removed upstream in gaze 0.15.0 (pre-flight). '
        .'Switch GAZE_SAFETY_NET_BACKEND to nym (fetch the bundle with `gaze setup --safety-net nym`) '
        .'or set GAZE_SAFETY_NET=false.';

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
        if ($enabled && $backend === self::KIJI_DISTILBERT) {
            throw new GazeSafetyNetConfigException(self::KIJI_DISTILBERT_REMOVED, 2, null);
        }
    }
}
