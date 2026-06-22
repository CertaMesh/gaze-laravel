<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

enum Variant: string
{
    case StdinParse = 'StdinParse';
    case EmptyInput = 'EmptyInput';
    case InputTooLarge = 'InputTooLarge';
    case InvalidEncoding = 'InvalidEncoding';
    case PolicyConfig = 'PolicyConfig';
    case PolicyConfigDetail = 'PolicyConfigDetail';
    case PolicySchemaUnsupported = 'PolicySchemaUnsupported';
    case SafetyNetConfig = 'SafetyNetConfig';
    case SafetyNet = 'SafetyNet';
    case SafetyNetArtifactMissing = 'SafetyNetArtifactMissing';
    case AuditPurgeIso8601 = 'AuditPurgeIso8601';
    case UnknownToken = 'UnknownToken';
    case UnsupportedSessionScope = 'UnsupportedSessionScope';
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

        if ($error === 'SafetyNetConfig' && array_key_exists('detail', $decoded)) {
            return self::SafetyNetConfig;
        }

        if ($error === 'SafetyNet' && isset($decoded['variant']) && is_string($decoded['variant'])) {
            return self::SafetyNet;
        }

        if ($error === 'UnsupportedSessionScope' && isset($decoded['variant']) && is_string($decoded['variant'])) {
            return self::UnsupportedSessionScope;
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
            // Exit 1 is a caller-bug bucket that can carry several shape errors.
            // When stderr is missing, StdinParse is the most conservative tie-break.
            default => self::StdinParse,
        };
    }

    public function exitBucket(): int
    {
        return match ($this) {
            self::StdinParse, self::EmptyInput, self::InputTooLarge, self::InvalidEncoding => 1,
            self::PolicyConfig, self::PolicyConfigDetail, self::PolicySchemaUnsupported, self::SafetyNetArtifactMissing, self::AuditPurgeIso8601 => 2,
            self::SafetyNetConfig,
            self::SafetyNet,
            self::UnknownToken,
            self::UnsupportedSessionScope,
            self::InvalidSignature,
            self::InvalidBlobVersion,
            self::BlobExpired,
            self::Pipeline => 3,
            self::Io, self::PolicyOpen => 4,
            self::SigPipe => 141,
        };
    }
}
