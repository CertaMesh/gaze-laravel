<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Daemon;

/**
 * Decoded daemon-success envelope.
 *
 * Mirrors the spec wire shape:
 *
 *   {"session_id":"x","clean_text":"…","manifest":[…],"tokens":[…]}
 *
 * `raw` carries the full decoded JSON line so adopters can read fields the
 * adapter does not yet surface (forward-compat). Fields not in the spec
 * appear in `raw` without breaking the typed accessors.
 */
final readonly class CleanResponse
{
    /**
     * @param  array<mixed, mixed>  $manifest
     * @param  array<mixed, mixed>  $tokens
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $sessionId,
        public string $cleanText,
        public array $manifest,
        public array $tokens,
        public array $raw,
    ) {}

    /**
     * Build from a decoded JSON line. Caller is responsible for verifying the
     * line is a success envelope (no `error` key) before calling.
     *
     * @param  array<string, mixed>  $decoded
     */
    public static function fromArray(array $decoded): self
    {
        $sessionId = $decoded['session_id'] ?? '';
        $cleanText = $decoded['clean_text'] ?? '';
        $manifest = $decoded['manifest'] ?? [];
        $tokens = $decoded['tokens'] ?? [];

        return new self(
            sessionId: is_string($sessionId) ? $sessionId : '',
            cleanText: is_string($cleanText) ? $cleanText : '',
            manifest: is_array($manifest) ? $manifest : [],
            tokens: is_array($tokens) ? $tokens : [],
            raw: $decoded,
        );
    }
}
