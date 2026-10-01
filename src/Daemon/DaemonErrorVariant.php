<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Daemon;

/**
 * Wire-error taxonomy for `gaze daemon` JSONL responses.
 *
 * Upstream variants are the `error` names `commands/daemon.rs` writes to
 * stdout, pinned by `tests/Contract/DaemonErrorVariantContractTest.php`:
 * `JsonMalformed`, `ProtocolInvalid`, `Pipeline`, `PipelineInvariant`, and
 * the safety-net failures. The daemon writes a safety-net failure's own
 * variant as `error` (one-shot `clean` nests it under `SafetyNet`), so those
 * cases carry a `SafetyNet` prefix — wire `Timeout` is `SafetyNetTimeout`,
 * wire `Unavailable` is `SafetyNetUnavailable`.
 *
 * Adapter-introduced (transport surface owned by this package, never parsed
 * from the wire): `Transport` (broken pipe / EOF), `Timeout` (per-request
 * deadline), `Unavailable` (feature-gated build missing `daemon` subverb).
 *
 * `Unknown` is the forward-compat sink — any wire variant the adapter does
 * not yet recognise lands here. Adopters MUST include a `default` arm in
 * `match($variant)` blocks.
 */
enum DaemonErrorVariant: string
{
    case JsonMalformed = 'JsonMalformed';
    case ProtocolInvalid = 'ProtocolInvalid';
    case Pipeline = 'Pipeline';
    case PipelineInvariant = 'PipelineInvariant';
    case SafetyNetSuspectedLeak = 'SafetyNetSuspectedLeak';
    case SafetyNetUnavailable = 'SafetyNetUnavailable';
    case SafetyNetWeightsMissing = 'SafetyNetWeightsMissing';
    case SafetyNetModelUnavailable = 'SafetyNetModelUnavailable';
    case SafetyNetModelIntegrityMismatch = 'SafetyNetModelIntegrityMismatch';
    case SafetyNetInputTooLarge = 'SafetyNetInputTooLarge';
    case SafetyNetTimeout = 'SafetyNetTimeout';
    case SafetyNetRuntime = 'SafetyNetRuntime';
    case SafetyNetInvalidOutput = 'SafetyNetInvalidOutput';
    case Transport = 'Transport';
    case Timeout = 'Timeout';
    case Unavailable = 'Unavailable';
    case Unknown = 'Unknown';

    /**
     * Map a wire `error` string to a variant. Unrecognised strings fall
     * through to `Unknown` rather than throwing — keeps the daemon line
     * loop alive when upstream ships a new variant. No wire name maps to
     * an adapter-introduced case, so `Transport` lands in `Unknown` too.
     */
    public static function fromWire(string $wire): self
    {
        return match ($wire) {
            'JsonMalformed' => self::JsonMalformed,
            'ProtocolInvalid' => self::ProtocolInvalid,
            'Pipeline' => self::Pipeline,
            'PipelineInvariant' => self::PipelineInvariant,
            'SuspectedLeak' => self::SafetyNetSuspectedLeak,
            'Unavailable' => self::SafetyNetUnavailable,
            'WeightsMissing' => self::SafetyNetWeightsMissing,
            'ModelUnavailable' => self::SafetyNetModelUnavailable,
            'ModelIntegrityMismatch' => self::SafetyNetModelIntegrityMismatch,
            'InputTooLarge' => self::SafetyNetInputTooLarge,
            'Timeout' => self::SafetyNetTimeout,
            'Runtime' => self::SafetyNetRuntime,
            'InvalidOutput' => self::SafetyNetInvalidOutput,
            default => self::Unknown,
        };
    }
}
