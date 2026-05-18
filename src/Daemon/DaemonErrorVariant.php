<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Daemon;

/**
 * Wire-error taxonomy for `gaze daemon` JSONL responses.
 *
 * Spec variants (upstream): `JsonMalformed`, `Pipeline`.
 * Adapter-introduced (transport surface owned by this package):
 * `Transport` (broken pipe / EOF), `Timeout` (per-request deadline),
 * `Unavailable` (feature-gated build missing `daemon` subverb).
 *
 * `Unknown` is the forward-compat sink — any wire variant the adapter does
 * not yet recognise lands here. Adopters MUST include a `default` arm in
 * `match($variant)` blocks; doctor's `--deep` probe surfaces a warning when
 * upstream `gaze daemon --help` lists a variant not represented here.
 */
enum DaemonErrorVariant: string
{
    case JsonMalformed = 'JsonMalformed';
    case Pipeline = 'Pipeline';
    case Transport = 'Transport';
    case Timeout = 'Timeout';
    case Unavailable = 'Unavailable';
    case Unknown = 'Unknown';

    /**
     * Map a wire `error` string to a variant. Unrecognised strings fall
     * through to `Unknown` rather than throwing — keeps the daemon line
     * loop alive when upstream ships a new variant.
     */
    public static function fromWire(string $wire): self
    {
        return self::tryFrom($wire) ?? self::Unknown;
    }
}
