<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Daemon\SessionIdDigest;

/**
 * Exception thrown by the long-lived `gaze daemon` JSONL adapter.
 *
 * Daemon errors are stdout JSON envelopes — they have no stderr payload to
 * hash like the one-shot `Gaze::clean()` failures. The parent ctor receives
 * `stderrHash = null` (no stderr stream ever existed); `toLogContext()` is
 * overridden to surface the envelope `raw` payload instead.
 *
 * Session ids are adopter-chosen and may be built from user data, so the
 * message and `toLogContext()` carry only their {@see SessionIdDigest}
 * (#181). `sessionId()` and `raw()` keep the raw values for code that needs
 * them; nothing logs them automatically.
 *
 * This class is intentionally NOT `Retryable`: queue retry policy is the
 * adopter's responsibility, mirroring the one-shot semantics of the
 * underlying `gaze` binary. Transport / timeout subclasses inherit the
 * same posture.
 */
class GazeDaemonException extends GazeIntegrityException
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
     * The raw, adopter-chosen session id. Not logged by the adapter; log
     * `toLogContext()` instead, which carries only its digest.
     */
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
     * shape carries `{daemon_variant, session_id_sha256, raw}` instead.
     * Adopters that pipe `toLogContext()` into structured logs branch on
     * `instanceof GazeDaemonException` to read the daemon shape.
     *
     * `raw` is the envelope with every adopter-derived string swapped for a
     * digest: `session_id` becomes `session_id_sha256` (the same
     * {@see SessionIdDigest}), and `clean_text` / `raw_line` become
     * `clean_text_sha256` / `raw_line_sha256` (full SHA-256, like
     * `stderr_sha256`). `raw()` keeps the original envelope.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'daemon_variant' => $this->daemonVariant->value,
            'session_id_sha256' => $this->sessionId === null ? null : SessionIdDigest::of($this->sessionId),
            'raw' => self::logSafeRaw($this->raw),
        ];
    }

    public function logLevel(): string
    {
        return 'warning';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function logSafeRaw(array $raw): array
    {
        $safe = [];
        foreach ($raw as $key => $value) {
            if ($key === 'session_id') {
                $safe['session_id_sha256'] = is_string($value) ? SessionIdDigest::of($value) : null;
            } elseif ($key === 'clean_text' || $key === 'raw_line') {
                $safe[$key.'_sha256'] = is_string($value) ? hash('sha256', $value) : null;
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
