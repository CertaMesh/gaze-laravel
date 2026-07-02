<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Contracts;

use CertaMesh\Gaze\Entry;
use CertaMesh\Gaze\GazeSession;

/**
 * Public service contract for the gaze pseudonymization runtime.
 *
 * Bound in the container alongside the concrete `CertaMesh\Gaze\Gaze`
 * (both resolve to the same singleton) and returned by the `Gaze` facade.
 * Type-hint this contract instead of the concrete class so test doubles
 * (`CertaMesh\Gaze\Testing\FakeGaze`, `Gaze::fake()`) satisfy your
 * signatures without extending process-invoking concretes.
 *
 * The `@internal` audit process runners (`runForAuditPurge` /
 * `runForAuditQuery`) are deliberately NOT part of this contract — they
 * live on the narrower `Contracts\AuditRunner` consumed by the audit
 * subsystem only.
 */
interface Gaze
{
    /**
     * Run the reversible clean pipeline and return the pseudonymized
     * session (clean text + encrypted restore blob + detection inventory).
     */
    public function clean(string $text, ?float $threshold = null): GazeSession;

    /**
     * One-way redaction helper: clean() detection, then replace every
     * detected token with a masked label. Non-reversible — no restore
     * counterpart.
     *
     * @param  (callable(Entry): string)|null  $replace
     */
    public function mask(string $text, ?callable $replace = null): string;

    /**
     * Re-identify a previously cleaned text using the session's encrypted blob.
     */
    public function restore(GazeSession $session, string $text): string;

    /**
     * Resolve the audit verbs service, optionally overriding the configured
     * audit DB path for this call.
     */
    public function audit(?string $auditDbPath = null): AuditService;

    /**
     * Resolve the daemon manager for the long-lived `gaze daemon` runtime.
     */
    public function daemon(): DaemonManager;
}
