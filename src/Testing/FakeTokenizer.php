<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

/**
 * Shared deterministic masking for the Gaze test doubles.
 *
 * The single source of the fake token grammar: both the one-shot
 * `FakeGaze::clean()` path and the daemon `FakeDaemonManager::clean()`
 * path delegate here, so `Gaze::fake()` masks identically no matter
 * which surface an adopter exercises. No detection logic lives in PHP —
 * this only produces a predictable masked *shape* for assertions.
 *
 * Grammar (mirrors the real binary's token classes):
 *  - wrapped class tokens (`<Email_1>`, `<Name_2>`, …) normalize to `<Name_1>`
 *  - wrapped custom tokens (`<Custom:order_id_9>`) normalize to `<Custom:order_id_1>`
 *  - bare tokens (`name_2`, `custom:sku_3`) keep their bare/lowercase shape
 *  - format-preserving fake emails (`email2@example.test`) stay format-preserving
 *  - real email addresses mask to `<Email_1>`
 *  - the literal `Alice` masks to `Name_1` as a last-resort name fallback
 *
 * @internal test-support detail of `FakeGaze` / `FakeDaemonManager`; the
 *           public seams are those fakes and the `Gaze` facade assertions.
 */
final class FakeTokenizer
{
    /**
     * Token-shaped alternatives first (so `email1@example.test` hits its
     * format-preserving branch before the generic email alternative at the
     * end can claim it).
     */
    private const TOKEN_PATTERN = '/<(?:Email|Name|Location|Organization)_\d+>|<Custom:[a-z0-9_]*_\d+>|\b(?:email|name|location|organization)_\d+\b|\bcustom:[a-z0-9_]*_\d+\b|\bemail\d+@example\.test\b|<[A-Z][a-zA-Z]+_\d+>|<[a-z][a-z_]+_\d+>|\b[A-Z][a-zA-Z]+_\d+\b|\b[a-z][a-z_]+_\d+\b|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    public static function mask(string $text): string
    {
        $cleanText = preg_replace_callback(
            self::TOKEN_PATTERN,
            static function (array $match): string {
                $token = $match[0];

                if (preg_match('/^email\d+@example\.test$/', $token) === 1) {
                    return 'email1@example.test';
                }

                if (str_contains($token, '@')) {
                    return '<Email_1>';
                }

                if (str_starts_with($token, '<Custom:')) {
                    return '<Custom:order_id_1>';
                }

                if (str_starts_with($token, 'custom:')) {
                    return 'custom:order_id_1';
                }

                if (str_starts_with($token, '<')) {
                    return ctype_lower($token[1]) ? '<name_1>' : '<Name_1>';
                }

                return ctype_lower($token[0]) ? 'name_1' : 'Name_1';
            },
            $text,
        );

        if (! is_string($cleanText) || $cleanText === $text) {
            return str_replace('Alice', 'Name_1', $text);
        }

        return $cleanText;
    }
}
