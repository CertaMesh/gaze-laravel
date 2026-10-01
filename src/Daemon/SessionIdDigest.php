<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Daemon;

/**
 * Short, stable digest of an adopter-chosen daemon session id, used in
 * exception messages and `toLogContext()` instead of the raw id (#181).
 *
 * Adopters pick the ids they pass to `Gaze::daemon()->session($id)`, and an
 * id built from user data (an email, a customer number) must not reach logs
 * or error trackers raw. The digest is the first 12 hex characters of the
 * id's SHA-256: stable across processes, so log lines for one session still
 * correlate, and an adopter can compute it for a known id to search logs.
 *
 * It is a correlation label, not anonymisation: an unsalted digest of a
 * low-entropy id (a short number, a guessable email) can be recovered by
 * hashing candidates. Session ids should still be opaque.
 *
 * @internal
 */
final class SessionIdDigest
{
    public const LENGTH = 12;

    public static function of(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, self::LENGTH);
    }
}
