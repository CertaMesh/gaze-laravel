<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

/**
 * Exit 1 is a caller-bug bucket that can carry several shape errors. When the
 * stderr envelope is missing, default to StdinParse as the most conservative
 * tie-break because it preserves the "bad request to the CLI" semantics.
 */
enum Variant: string
{
    case StdinParse = 'StdinParse';
    case EmptyInput = 'EmptyInput';
    case InputTooLarge = 'InputTooLarge';
    case InvalidEncoding = 'InvalidEncoding';
    case PolicyConfig = 'PolicyConfig';
    case PolicyConfigDetail = 'PolicyConfigDetail';
    case AuditPurgeIso8601 = 'AuditPurgeIso8601';
    case UnknownToken = 'UnknownToken';
    case InvalidSignature = 'InvalidSignature';
    case InvalidBlobVersion = 'InvalidBlobVersion';
    case BlobExpired = 'BlobExpired';
    case Pipeline = 'Pipeline';
    case Io = 'Io';
    case SigPipe = 'SigPipe';
    case PolicyOpen = 'PolicyOpen';

    public static function tryFromStderr(string $stderr, int $actualExit): self
    {
        /** @var array{error?: mixed, exit?: mixed}|null $decoded */
        $decoded = json_decode($stderr, true);

        if (! is_array($decoded)) {
            return self::unknownFor($actualExit);
        }

        $error = $decoded['error'] ?? null;
        $embeddedExit = $decoded['exit'] ?? null;

        if (! is_string($error) || ! is_int($embeddedExit) || $embeddedExit !== $actualExit) {
            return self::unknownFor($actualExit);
        }

        // Upstream collapses PolicyConfig and PolicyConfigDetail under the same wire
        // name (`crates/gaze-cli/src/error.rs:39-55`) and disambiguates only via the
        // `detail` sidecar. The synthetic PolicyConfigDetail backing value never
        // appears on stderr; this branch is the only path that resolves it.
        if ($error === 'PolicyConfig' && array_key_exists('detail', $decoded)) {
            return self::PolicyConfigDetail;
        }

        return self::tryFrom($error) ?? self::unknownFor($actualExit);
    }

    public static function unknownFor(int $exit): self
    {
        return match ($exit) {
            2 => self::PolicyConfig,
            3 => self::UnknownToken,
            4 => self::Io,
            141 => self::SigPipe,
            default => self::StdinParse,
        };
    }

    public function exitBucket(): int
    {
        return match ($this) {
            self::StdinParse, self::EmptyInput, self::InputTooLarge, self::InvalidEncoding => 1,
            self::PolicyConfig, self::PolicyConfigDetail, self::AuditPurgeIso8601 => 2,
            self::UnknownToken,
            self::InvalidSignature,
            self::InvalidBlobVersion,
            self::BlobExpired,
            self::Pipeline => 3,
            self::Io, self::PolicyOpen => 4,
            self::SigPipe => 141,
        };
    }
}
