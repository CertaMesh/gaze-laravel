<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonEnvelopeParser;
use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Exceptions\GazeDaemonException;
use CertaMesh\Gaze\Tests\Fixtures\UpstreamErrorNames;

/**
 * Source-of-truth fixture mirrored from upstream `crates/gaze-cli/src/commands/daemon.rs`
 * for gaze v0.15.1. Each row pins one `error` name the daemon writes to stdout:
 *   - 0: DaemonErrorVariant case name on the PHP side
 *   - 1: the `{session_id, error, detail}` envelope upstream writes
 *
 * Where the names come from:
 *   - JsonMalformed / ProtocolInvalid: `Daemon::handle_line`, before a session
 *     exists, so `session_id` is null (verified against the real binary).
 *   - Pipeline / PipelineInvariant: `DaemonError::variant()` for
 *     `CliError::Pipeline` and `DaemonError::Invariant`.
 *   - Everything else: `CliError::SafetyNetFailure { variant }`, written as
 *     `error` verbatim — from `map_safety_net_error` (`pipeline/run.rs`) and,
 *     for SuspectedLeak, `enforce_safety_net_mode`. Wire `Timeout` and
 *     `Unavailable` are the safety net's, not the adapter's request deadline or
 *     missing `daemon` subverb, hence the `SafetyNet` prefix on every such case.
 *
 * The dataset key is the wire name.
 */
const UPSTREAM_DAEMON_ERRORS = [
    'JsonMalformed' => ['JsonMalformed', ['session_id' => null, 'error' => 'JsonMalformed', 'detail' => 'malformed JSON line']],
    'ProtocolInvalid' => ['ProtocolInvalid', ['session_id' => null, 'error' => 'ProtocolInvalid', 'detail' => 'missing session_id']],
    'Pipeline' => ['Pipeline', ['session_id' => 's1', 'error' => 'Pipeline', 'detail' => 'gaze daemon request failed closed']],
    'PipelineInvariant' => ['PipelineInvariant', ['session_id' => 's1', 'error' => 'PipelineInvariant', 'detail' => 'unexpected non-text clean document']],
    'SuspectedLeak' => ['SafetyNetSuspectedLeak', ['session_id' => 's1', 'error' => 'SuspectedLeak', 'detail' => 'gaze daemon request failed closed']],
    'Unavailable' => ['SafetyNetUnavailable', ['session_id' => 's1', 'error' => 'Unavailable', 'detail' => 'gaze daemon request failed closed']],
    'WeightsMissing' => ['SafetyNetWeightsMissing', ['session_id' => 's1', 'error' => 'WeightsMissing', 'detail' => 'gaze daemon request failed closed']],
    'ModelUnavailable' => ['SafetyNetModelUnavailable', ['session_id' => 's1', 'error' => 'ModelUnavailable', 'detail' => 'gaze daemon request failed closed']],
    'ModelIntegrityMismatch' => ['SafetyNetModelIntegrityMismatch', ['session_id' => 's1', 'error' => 'ModelIntegrityMismatch', 'detail' => 'gaze daemon request failed closed']],
    'InputTooLarge' => ['SafetyNetInputTooLarge', ['session_id' => 's1', 'error' => 'InputTooLarge', 'detail' => 'gaze daemon request failed closed']],
    'Timeout' => ['SafetyNetTimeout', ['session_id' => 's1', 'error' => 'Timeout', 'detail' => 'gaze daemon request failed closed']],
    'Runtime' => ['SafetyNetRuntime', ['session_id' => 's1', 'error' => 'Runtime', 'detail' => 'gaze daemon request failed closed']],
    'InvalidOutput' => ['SafetyNetInvalidOutput', ['session_id' => 's1', 'error' => 'InvalidOutput', 'detail' => 'gaze daemon request failed closed']],
];

/**
 * Adapter-owned cases: the three faults `DaemonClient` raises itself, plus the
 * `Unknown` sink. No wire name maps to the first three.
 */
const ADAPTER_DAEMON_VARIANTS = ['Transport', 'Timeout', 'Unavailable', 'Unknown'];

/*
 * The upstream daemon error names deliberately left unmapped (they land in
 * `Unknown`) live in UpstreamErrorNames::UNMAPPED_DAEMON_ERRORS, shared with
 * the opt-in UpstreamErrorDriftTest that checks them against upstream source.
 */

it('upstream daemon error maps to its own case', function (string $case, array $envelope) {
    expect(DaemonErrorVariant::fromWire($envelope['error']))
        ->toBe(constant(DaemonErrorVariant::class.'::'.$case));
})->with(UPSTREAM_DAEMON_ERRORS);

it('parses the upstream envelope into a base GazeDaemonException', function (string $case, array $envelope) {
    $result = DaemonEnvelopeParser::parse(json_encode($envelope, JSON_THROW_ON_ERROR));

    expect($result)->toBeInstanceOf(GazeDaemonException::class)
        ->and($result::class)->toBe(GazeDaemonException::class);
    if ($result instanceof GazeDaemonException) {
        expect($result->daemonVariant())->toBe(constant(DaemonErrorVariant::class.'::'.$case))
            ->and($result->sessionId())->toBe($envelope['session_id'])
            ->and($result->getMessage())->toBe($envelope['detail']);
    }
})->with(UPSTREAM_DAEMON_ERRORS);

it('PHP enum is exactly the upstream set plus the adapter-owned cases (catches drift both ways)', function () {
    $expectedNames = [...array_map(fn (array $row) => $row[0], array_values(UPSTREAM_DAEMON_ERRORS)), ...ADAPTER_DAEMON_VARIANTS];
    $actualNames = array_map(fn (DaemonErrorVariant $v) => $v->name, DaemonErrorVariant::cases());
    sort($expectedNames);
    sort($actualNames);

    expect($actualNames)->toBe($expectedNames);
});

it('routes every other wire name to Unknown, adapter-only names included', function () {
    foreach ([...UpstreamErrorNames::UNMAPPED_DAEMON_ERRORS, 'Transport'] as $wire) {
        expect(DaemonErrorVariant::fromWire($wire))->toBe(DaemonErrorVariant::Unknown, "wire name {$wire}");
    }
});
