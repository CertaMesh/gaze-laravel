<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

/**
 * Per-rule detection entry surfaced from the upstream gaze CLI session.
 *
 * Mirrors the upstream `SnapshotEntry` shape (gaze/src/session.rs). Each entry
 * describes one tokenized span: which PII class fired, the original raw value,
 * its pseudonymous token, and the collision family.
 *
 * @see https://github.com/CertaMesh/gaze
 */
final readonly class Entry
{
    public function __construct(
        public string $class,
        public string $raw,
        public string $token,
        public ?string $family = null,
    ) {}

    /**
     * Build an Entry from a decoded JSON associative array.
     *
     * Missing optional fields default to null. Unknown fields are ignored for
     * forward compatibility with future upstream schema additions.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            class: (string) ($payload['class'] ?? ''),
            raw: (string) ($payload['raw'] ?? ''),
            token: (string) ($payload['token'] ?? ''),
            family: isset($payload['family']) && is_string($payload['family'])
                ? $payload['family']
                : null,
        );
    }
}
