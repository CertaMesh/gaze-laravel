<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use Devium\Toml\Toml;

/**
 * Best-effort, read-only access to the policy TOML for adapter pre-flights
 * and `gaze:doctor` probes. Policy semantics stay upstream: this only reads
 * the few keys a pre-flight needs, and never throws. An unreadable or
 * unparseable policy yields null, so the binary still reports its own
 * `PolicyOpen` / `PolicyConfig` error exactly as before.
 *
 * @internal
 */
final class PolicyFile
{
    /**
     * Parsed `[session] scope` per policy path, tagged with the stat
     * fingerprint (mtime, ctime, size, inode, device) it was read under. Process-wide on
     * purpose: it holds no request state, and a changed file gets a new
     * fingerprint, so long-lived workers (Octane, queue workers) pick up a
     * policy edit on the next call. One entry per distinct path.
     *
     * @var array<string, array{fingerprint: string, scope: ?string}>
     */
    private static array $sessionScopes = [];

    /**
     * Decode the policy TOML. Null when the file cannot be read or parsed.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $policyPath): ?array
    {
        $body = @file_get_contents($policyPath);
        if ($body === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $parsed */
            $parsed = Toml::decode($body, asArray: true);
        } catch (\Throwable) {
            return null;
        }

        return $parsed;
    }

    /**
     * The policy's raw `[session] scope` string, or null when the key is
     * absent, not a string, or the file cannot be read or parsed.
     *
     * Cached by path and stat fingerprint (mtime, ctime, size, inode,
     * device), so a long-lived worker (Octane, queue) pays one stat() per
     * call and parses once per policy change; ctime also catches a chmod.
     * Under PHP-FPM the cache dies with the request, so the first call of
     * each request reads the file — and skips the TOML parse entirely unless
     * the body contains `ephemeral` (the only value this pre-flight acts on;
     * the shipped policy never does). A failed read or parse is NOT cached,
     * so a permissions fix takes effect on the next call.
     */
    public static function sessionScope(string $policyPath): ?string
    {
        // PHP's stat cache would otherwise serve a stale mtime to a worker
        // that stats the same path on every call.
        clearstatcache(true, $policyPath);
        $stat = @stat($policyPath);
        if ($stat === false) {
            // Missing or unreadable: the binary reports PolicyOpen itself.
            return null;
        }

        $fingerprint = implode(':', [$stat['mtime'], $stat['ctime'], $stat['size'], $stat['ino'], $stat['dev']]);
        $cached = self::$sessionScopes[$policyPath] ?? null;
        if ($cached !== null && $cached['fingerprint'] === $fingerprint) {
            return $cached['scope'];
        }

        $body = @file_get_contents($policyPath);
        if ($body === false) {
            // Unreadable: not cached, the binary reports PolicyOpen itself.
            return null;
        }

        if (! str_contains($body, 'ephemeral')) {
            // Cheap negative: no value this pre-flight acts on can be set.
            self::$sessionScopes[$policyPath] = ['fingerprint' => $fingerprint, 'scope' => null];

            return null;
        }

        try {
            /** @var array<string, mixed> $parsed */
            $parsed = Toml::decode($body, asArray: true);
        } catch (\Throwable) {
            // Unparseable: not cached, the binary reports PolicyConfig itself.
            return null;
        }

        $session = $parsed['session'] ?? null;
        $scope = is_array($session) && is_string($session['scope'] ?? null) ? $session['scope'] : null;

        self::$sessionScopes[$policyPath] = ['fingerprint' => $fingerprint, 'scope' => $scope];

        return $scope;
    }

    /**
     * Drop the session-scope cache. Tests only.
     */
    public static function flushCache(): void
    {
        self::$sessionScopes = [];
    }
}
