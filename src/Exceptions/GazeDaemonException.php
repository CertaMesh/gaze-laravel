<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition;
use CertaMesh\Gaze\Queue\RetryAction;
use CertaMesh\Gaze\Queue\SafetyNetRetryMap;

/**
 * Exception thrown by the long-lived `gaze daemon` JSONL adapter.
 *
 * Daemon errors are stdout JSON envelopes — they have no stderr payload to
 * hash like the one-shot `Gaze::clean()` failures. The parent ctor receives
 * `stderrHash = null` (no stderr stream ever existed); `toLogContext()` is
 * overridden to surface the envelope `raw` payload instead.
 *
 * This class implements none of the static retry markers (`Retryable`,
 * `NonRetryable`, ...). Its {@see self::retryDisposition()} answers only for
 * the `SafetyNet*` variants, with the same map as the one-shot
 * `GazeSafetyNetFailureException`; every other variant returns
 * `RetryAction::Throw`, so its queue retry stays the adopter's call.
 * Transport / timeout subclasses inherit the same posture.
 */
class GazeDaemonException extends GazeIntegrityException implements HasRetryDisposition
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        string $message,
        public readonly ?string $sessionId,
        public readonly array $raw,
        public readonly DaemonErrorVariant $daemonVariant,
        ?\Throwable $previous = null,
    ) {
        // Daemon errors have no stderr — pass a null hash and rely on
        // toLogContext() for envelope-aware diagnostics. Exit code -1
        // mirrors the GazeResponseDecodeException precedent for line-
        // level errors with no upstream process exit.
        parent::__construct($message, -1, null, null, $previous);
    }

    public function daemonVariant(): DaemonErrorVariant
    {
        return $this->daemonVariant;
    }

    /**
     * Safety-net failures (`DaemonErrorVariant::SafetyNet*`) get the same
     * disposition as the one-shot variant of the same name
     * ({@see SafetyNetRetryMap}). Every other daemon variant returns
     * `RetryAction::Throw`, as before this method existed.
     */
    public function retryDisposition(): RetryAction
    {
        $safetyNetVariant = $this->daemonVariant->safetyNetVariant();

        return $safetyNetVariant === null
            ? RetryAction::Throw
            : SafetyNetRetryMap::for($safetyNetVariant);
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Daemon-shaped log context override. Returns a structurally different
     * payload than the parent (`{exit_code, error_variant, stderr_sha256}`)
     * — daemon errors are stdout envelopes, not stderr hashes — so the
     * shape carries `{daemon_variant, session_id, raw}` instead. Adopters
     * that pipe `toLogContext()` into structured logs branch on
     * `instanceof GazeDaemonException` to read the daemon shape.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'daemon_variant' => $this->daemonVariant->value,
            'session_id' => $this->sessionId,
            'raw' => $this->raw,
        ];
    }

    public function logLevel(): string
    {
        return 'warning';
    }
}
