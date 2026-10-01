<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Queue;

use CertaMesh\Gaze\Exceptions\GazeDaemonException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetFailureException;

/**
 * The one place that maps a safety-net failure variant to its queue retry
 * disposition. Keyed by the upstream variant name, so both paths share it:
 *
 *   - one-shot `gaze clean`: `{"error":"SafetyNet","variant":"<name>"}` →
 *     {@see GazeSafetyNetFailureException::retryDisposition()};
 *   - `gaze daemon`: `{"error":"<name>"}` → `DaemonErrorVariant::SafetyNet<name>`
 *     → {@see GazeDaemonException::retryDisposition()}.
 *
 * Unknown names fail closed ({@see RetryAction::Fail}).
 *
 * @internal Branch on `GazeRetryPolicy::classify()` or `retryDisposition()`;
 * the shape of this map is not part of the public API.
 */
final class SafetyNetRetryMap
{
    /**
     * Every `variant` string gaze 0.15.1 `clean` can emit for a safety-net
     * failure. Sources: `map_safety_net_error` in `crates/gaze-cli/src/
     * pipeline/run.rs` (the seven `SafetyNetError` variants of
     * `gaze-types`, with `Runtime` split off into `Timeout` when its message
     * says "timed out", plus `Unknown`), `enforce_safety_net_mode`
     * (`SuspectedLeak`) and `validate_safety_net_tolerant_gate`
     * (`TolerantModeDisabled`). The daemon writes the same names as `error`
     * for every one except `TolerantModeDisabled`, which only fails its
     * startup.
     *
     * Pinned by `tests/Contract/SafetyNetRetryMapContractTest.php`.
     */
    public const UPSTREAM = [
        // Transient. The backend exceeded `--safety-net-timeout-ms`. Load
        // dependent, so a later attempt can finish in time.
        'Timeout' => RetryAction::ReleaseWithBackoff,
        // Transient. The backend process crashed, exited non-zero, or its
        // pipes or inference session failed (OOM kill, resource pressure).
        // A persistent cause still stops at the job's `$tries`.
        'Runtime' => RetryAction::ReleaseWithBackoff,
        // Strict mode refused the output because the safety net flagged an
        // uncovered span. Kept as before: release, so the job can
        // pass once the policy covers the span, and alert, because a human
        // has to look at the leak signal.
        'SuspectedLeak' => RetryAction::ReleaseWithAlert,
        // Configuration. Upstream raises it when the backend is not set up:
        // `GAZE_NYM_MODEL_DIR` / `GAZE_OPENAI_FILTER_OPF` unset, an empty opf
        // command, or no safety-net model for the input locale. Retrying the
        // same deployment fails the same way.
        'Unavailable' => RetryAction::Fail,
        // Artifact. A model weight or checkpoint file is missing.
        'WeightsMissing' => RetryAction::Fail,
        // Artifact. The model or the opf command could not be loaded,
        // spawned, or verified (missing path, bad permissions).
        'ModelUnavailable' => RetryAction::Fail,
        // Artifact. A model file failed its SHA-256 pin. Never retry over a
        // tampered or half-written bundle.
        'ModelIntegrityMismatch' => RetryAction::Fail,
        // Input. The text exceeds `--safety-net-input-limit-bytes`; the same
        // input is always too large.
        'InputTooLarge' => RetryAction::Fail,
        // Artifact. The backend's output did not parse or broke the span
        // contract, which points at a model/command mismatch, not at load.
        'InvalidOutput' => RetryAction::Fail,
        // Configuration. `--safety-net-mode tolerant` (or a tolerant
        // fallback) without `GAZE_ALLOW_TOLERANT` in the environment.
        'TolerantModeDisabled' => RetryAction::Fail,
        // Upstream's own sink for a `SafetyNetError` variant its CLI does not
        // map yet. Fail closed.
        'Unknown' => RetryAction::Fail,
    ];

    /**
     * Legacy names. No gaze release has emitted either: every release since
     * the safety net shipped writes one of the {@see self::UPSTREAM} names.
     * `Other` is also the adapter's own label when the `variant` sidecar is
     * missing. Kept with their old dispositions so hand-built exceptions keep
     * behaving the same until 1.0.
     */
    public const LEGACY = [
        'Other' => RetryAction::ReleaseWithBackoff,
        'Unsupported' => RetryAction::Fail,
    ];

    public static function for(string $variant): RetryAction
    {
        return self::UPSTREAM[$variant] ?? self::LEGACY[$variant] ?? RetryAction::Fail;
    }

    /**
     * True when the variant has an explicit entry (upstream or legacy), so
     * its disposition is a decision rather than the fail-closed fallback.
     */
    public static function knows(string $variant): bool
    {
        return isset(self::UPSTREAM[$variant]) || isset(self::LEGACY[$variant]);
    }
}
