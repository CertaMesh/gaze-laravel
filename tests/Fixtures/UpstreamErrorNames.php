<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Tests\Fixtures;

/**
 * Upstream error names the adapter deliberately leaves unmapped, and retired
 * ones it still parses. Shared by VariantContractTest,
 * DaemonErrorVariantContractTest and the opt-in UpstreamErrorDriftTest
 * (GAZE_UPSTREAM_SRC), so a pin bump edits one list per surface.
 */
final class UpstreamErrorNames
{
    /**
     * `Variant` cases upstream removed while the PHP case is kept, deprecated,
     * for BC. VariantContractTest's reverse-drift test tolerates exactly these.
     *   - UnsupportedSessionScope: removed in gaze 0.15.0 (#618); only the
     *     no-policy clean path ever emitted it, and the adapter always passes
     *     --policy.
     */
    public const RETIRED_VARIANTS = ['UnsupportedSessionScope'];

    /**
     * `Variant` cases the adapter builds itself; no gaze release writes them:
     *   - SigPipe: Gaze::buildException() makes it for exit 141 with empty
     *     stderr (a reader closed the pipe early).
     */
    public const ADAPTER_VARIANTS = ['SigPipe'];

    /**
     * `DaemonErrorVariant` cases the adapter builds itself (transport surface,
     * never parsed from the wire), plus the `Unknown` sink.
     */
    public const ADAPTER_DAEMON_ERRORS = ['Transport', 'Timeout', 'Unavailable', 'Unknown'];

    /**
     * `variant_name()` wire names in upstream `error.rs` deliberately NOT
     * mapped to a `Variant` case, because no command the adapter runs (clean,
     * restore, audit, daemon, proxy) can emit them:
     *   - Setup (exit 2): `gaze setup` only.
     *   - IndexNerModelMissing (exit 2): `gaze index` only.
     *   - Document (5) / Mcp (6) / Proxy (7): feature-gated `document`, `mcp`
     *     and proxy-control errors; the proxy artisans pass the binary's output
     *     through verbatim instead of parsing it.
     * Should one ever surface on a mapped path, Variant::unknownFor() still
     * classifies it by exit code.
     */
    public const UNMAPPED_VARIANTS = ['Setup', 'IndexNerModelMissing', 'Document', 'Mcp', 'Proxy'];

    /**
     * Upstream daemon error names deliberately NOT mapped; they land in
     * `DaemonErrorVariant::Unknown`:
     *   - SafetyNetConfig, PolicyConfig, PolicyOpen, Io, CliError: listed in
     *     `DaemonError::variant()`, but no `clean_request` path builds those
     *     `CliError`s at 0.15.1 (`CliError` is the catch-all name). All but
     *     `CliError` do occur at startup (`Daemon::new`), as one-shot stderr JSON
     *     before the daemon exits; the client then reads EOF →
     *     `GazeDaemonTransportException`.
     *   - TolerantModeDisabled: a `SafetyNetFailure` raised only in `Daemon::new`.
     *   - Unknown: `map_safety_net_error`'s arm for a future `SafetyNetError`
     *     variant (dead at 0.15.1, every variant is matched); it is the sink anyway.
     *   - AuditWriteFailed: a failed eviction audit write (#570), stderr only.
     */
    public const UNMAPPED_DAEMON_ERRORS = ['SafetyNetConfig', 'PolicyConfig', 'PolicyOpen', 'Io', 'CliError', 'TolerantModeDisabled', 'Unknown', 'AuditWriteFailed'];
}
