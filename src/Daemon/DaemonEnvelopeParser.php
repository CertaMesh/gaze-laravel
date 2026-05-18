<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Daemon;

use Naoray\GazeLaravel\Exceptions\GazeDaemonException;
use Naoray\GazeLaravel\Exceptions\GazeDaemonTimeoutException;
use Naoray\GazeLaravel\Exceptions\GazeDaemonTransportException;

/**
 * Maps one JSONL response line to either a `CleanResponse` (success) or
 * the appropriate `GazeDaemonException` subclass (error).
 *
 * Surface-distinct variants get dedicated exception subclasses so adopter
 * catch ladders can react differently:
 *
 *  - `Transport` → `GazeDaemonTransportException`  (broken pipe / EOF)
 *  - `Timeout`   → `GazeDaemonTimeoutException`    (per-request deadline)
 *  - others      → `GazeDaemonException` carrying the variant enum
 */
final class DaemonEnvelopeParser
{
    /**
     * @return CleanResponse|GazeDaemonException
     */
    public static function parse(string $line, ?string $expectedSessionId = null): CleanResponse|GazeDaemonException
    {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new GazeDaemonException(
                'daemon response was not valid JSON',
                $expectedSessionId,
                ['raw_line' => $line],
                DaemonErrorVariant::JsonMalformed,
                $e,
            );
        }

        if (! is_array($decoded)) {
            return new GazeDaemonException(
                'daemon response was not a JSON object',
                $expectedSessionId,
                ['raw_line' => $line],
                DaemonErrorVariant::JsonMalformed,
            );
        }

        if (isset($decoded['error'])) {
            return self::buildErrorException($decoded);
        }

        return CleanResponse::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private static function buildErrorException(array $decoded): GazeDaemonException
    {
        $wire = is_string($decoded['error'] ?? null) ? (string) $decoded['error'] : '';
        $variant = DaemonErrorVariant::fromWire($wire);
        $detail = isset($decoded['detail']) && is_string($decoded['detail'])
            ? $decoded['detail']
            : "daemon returned typed error: {$wire}";
        $sessionId = isset($decoded['session_id']) && is_string($decoded['session_id'])
            ? $decoded['session_id']
            : null;

        return match ($variant) {
            DaemonErrorVariant::Transport => new GazeDaemonTransportException($detail, $sessionId, $decoded),
            DaemonErrorVariant::Timeout => new GazeDaemonTimeoutException($detail, $sessionId, $decoded),
            default => new GazeDaemonException($detail, $sessionId, $decoded, $variant),
        };
    }
}
