<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Daemon;

use CertaMesh\Gaze\Exceptions\GazeDaemonException;

/**
 * Maps one JSONL response line to either a `CleanResponse` (success) or
 * a `GazeDaemonException` carrying the variant enum (error).
 *
 * Every wire error is the base class. The `Transport` / `Timeout`
 * subclasses belong to faults `DaemonClient` detects itself; upstream's
 * safety-net `Timeout` is `SafetyNetTimeout`, not the request deadline.
 */
final class DaemonEnvelopeParser
{
    public static function parse(#[\SensitiveParameter] string $line, #[\SensitiveParameter] ?string $expectedSessionId = null): CleanResponse|GazeDaemonException
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
    private static function buildErrorException(#[\SensitiveParameter] array $decoded): GazeDaemonException
    {
        $wire = is_string($decoded['error'] ?? null) ? (string) $decoded['error'] : '';
        $variant = DaemonErrorVariant::fromWire($wire);
        $detail = isset($decoded['detail']) && is_string($decoded['detail'])
            ? $decoded['detail']
            : "daemon returned typed error: {$wire}";
        $sessionId = isset($decoded['session_id']) && is_string($decoded['session_id'])
            ? $decoded['session_id']
            : null;

        return new GazeDaemonException($detail, $sessionId, $decoded, $variant);
    }
}
