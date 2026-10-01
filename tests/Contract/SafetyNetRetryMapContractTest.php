<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonEnvelopeParser;
use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Exceptions\GazeDaemonException;
use CertaMesh\Gaze\Exceptions\GazeDaemonFeatureUnsupportedException;
use CertaMesh\Gaze\Exceptions\GazeDaemonTimeoutException;
use CertaMesh\Gaze\Exceptions\GazeDaemonTransportException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetFailureException;
use CertaMesh\Gaze\Queue\GazeRetryPolicy;
use CertaMesh\Gaze\Queue\RetryAction;
use CertaMesh\Gaze\Queue\SafetyNetRetryMap;
use CertaMesh\Gaze\Variant;

/**
 * Source-of-truth fixture mirrored from upstream gaze v0.15.1: every `variant`
 * string `gaze clean` can write in `{"error":"SafetyNet","variant":…}`, with
 * the retry disposition this package gives it.
 *
 * Where the names come from (`crates/gaze-cli/src/pipeline/run.rs`):
 *   - `map_safety_net_error`: one per `SafetyNetError` variant
 *     (`crates/gaze-types/src/lib.rs`, 7 variants), with `Runtime` split into
 *     `Timeout` when its message says "timed out", plus `Unknown` for a
 *     variant the CLI does not map yet.
 *   - `enforce_safety_net_mode`: `SuspectedLeak` (strict mode only).
 *   - `validate_safety_net_tolerant_gate`: `TolerantModeDisabled` (verified
 *     against the real 0.15.1 binary: `clean --safety-net-mode tolerant`
 *     without `GAZE_ALLOW_TOLERANT`).
 *
 * The dataset key is the variant name.
 */
const UPSTREAM_SAFETY_NET_DISPOSITIONS = [
    'Timeout' => ['Timeout', RetryAction::ReleaseWithBackoff],
    'Runtime' => ['Runtime', RetryAction::ReleaseWithBackoff],
    'SuspectedLeak' => ['SuspectedLeak', RetryAction::ReleaseWithAlert],
    'Unavailable' => ['Unavailable', RetryAction::Fail],
    'WeightsMissing' => ['WeightsMissing', RetryAction::Fail],
    'ModelUnavailable' => ['ModelUnavailable', RetryAction::Fail],
    'ModelIntegrityMismatch' => ['ModelIntegrityMismatch', RetryAction::Fail],
    'InputTooLarge' => ['InputTooLarge', RetryAction::Fail],
    'InvalidOutput' => ['InvalidOutput', RetryAction::Fail],
    'TolerantModeDisabled' => ['TolerantModeDisabled', RetryAction::Fail],
    'Unknown' => ['Unknown', RetryAction::Fail],
];

/**
 * Names no gaze release emits, kept with their old dispositions for BC.
 */
const LEGACY_SAFETY_NET_DISPOSITIONS = [
    'Other' => ['Other', RetryAction::ReleaseWithBackoff],
    'Unsupported' => ['Unsupported', RetryAction::Fail],
];

/**
 * Upstream variants the daemon never writes per request: `TolerantModeDisabled`
 * only fails `Daemon::new`, and `Unknown` lands in the generic
 * `DaemonErrorVariant::Unknown` sink.
 */
const SAFETY_NET_VARIANTS_WITHOUT_DAEMON_CASE = ['TolerantModeDisabled', 'Unknown'];

function snrm_oneShot(string $variant): GazeSafetyNetFailureException
{
    return new GazeSafetyNetFailureException('safety net failed', 3, hash('sha256', ''), $variant);
}

function snrm_daemon(DaemonErrorVariant $variant): GazeDaemonException
{
    return new GazeDaemonException('gaze daemon request failed closed', 's1', [], $variant);
}

/**
 * @return list<DaemonErrorVariant>
 */
function snrm_daemonSafetyNetCases(): array
{
    return array_values(array_filter(
        DaemonErrorVariant::cases(),
        fn (DaemonErrorVariant $case) => str_starts_with($case->name, 'SafetyNet'),
    ));
}

it('maps exactly the upstream variant set, no more and no less (catches drift both ways)', function () {
    $expected = array_keys(UPSTREAM_SAFETY_NET_DISPOSITIONS);
    $actual = array_keys(SafetyNetRetryMap::UPSTREAM);
    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected)
        ->and(array_intersect(array_keys(SafetyNetRetryMap::LEGACY), $actual))->toBe([]);
});

it('gives every upstream variant an explicit disposition', function (string $variant, RetryAction $action) {
    expect(SafetyNetRetryMap::knows($variant))->toBeTrue()
        ->and(SafetyNetRetryMap::for($variant))->toBe($action);
})->with(UPSTREAM_SAFETY_NET_DISPOSITIONS);

it('classifies one-shot clean failures per variant', function (string $variant, RetryAction $action) {
    $exception = snrm_oneShot($variant);

    expect($exception->retryDisposition())->toBe($action)
        ->and(GazeRetryPolicy::classify($exception))->toBe($action)
        ->and($exception->isRetryable())->toBe($action === RetryAction::ReleaseWithBackoff)
        ->and($exception->isRetryableWithAlert())->toBe($action === RetryAction::ReleaseWithAlert)
        ->and($exception->isNonRetryable())->toBe($action === RetryAction::Fail);
})->with([...UPSTREAM_SAFETY_NET_DISPOSITIONS, ...LEGACY_SAFETY_NET_DISPOSITIONS]);

it('classifies the stderr envelope the binary writes, not just a hand-built exception', function (string $variant, RetryAction $action) {
    $stderr = json_encode(['error' => 'SafetyNet', 'exit' => 3, 'variant' => $variant], JSON_THROW_ON_ERROR);
    $exception = Variant::tryFromStderr($stderr, 3)->toException('clean', 3, hash('sha256', $stderr), $stderr);

    expect($exception)->toBeInstanceOf(GazeSafetyNetFailureException::class)
        ->and(GazeRetryPolicy::classify($exception))->toBe($action);
})->with(UPSTREAM_SAFETY_NET_DISPOSITIONS);

it('fails unknown variants closed', function (string $variant) {
    $exception = snrm_oneShot($variant);

    expect(SafetyNetRetryMap::knows($variant))->toBeFalse()
        ->and(SafetyNetRetryMap::for($variant))->toBe(RetryAction::Fail)
        ->and(GazeRetryPolicy::classify($exception))->toBe(RetryAction::Fail)
        ->and($exception->isRetryable())->toBeFalse()
        ->and($exception->isRetryableWithAlert())->toBeFalse()
        ->and($exception->isNonRetryable())->toBeFalse();
})->with([
    'future variant' => ['SomeFutureVariant'],
    'empty' => [''],
    'wrong case' => ['timeout'],
    'daemon case name, not a wire name' => ['SafetyNetTimeout'],
]);

it('backs every daemon SafetyNet* case with an upstream variant that round-trips through fromWire', function () {
    $cases = snrm_daemonSafetyNetCases();

    expect($cases)->not->toBe([]);
    foreach ($cases as $case) {
        $variant = $case->safetyNetVariant();

        expect($variant)->not->toBeNull("{$case->name} has no upstream variant")
            ->and(array_key_exists((string) $variant, SafetyNetRetryMap::UPSTREAM))->toBeTrue("{$case->name} → {$variant} is not an upstream variant")
            ->and(DaemonErrorVariant::fromWire((string) $variant))->toBe($case);
    }
});

it('has a daemon SafetyNet* case for every upstream variant the daemon writes per request', function () {
    $daemonVariants = array_map(
        fn (DaemonErrorVariant $case) => $case->safetyNetVariant(),
        snrm_daemonSafetyNetCases(),
    );
    $expected = array_values(array_diff(array_keys(SafetyNetRetryMap::UPSTREAM), SAFETY_NET_VARIANTS_WITHOUT_DAEMON_CASE));
    sort($daemonVariants);
    sort($expected);

    expect($daemonVariants)->toBe($expected);
});

it('gives a daemon safety-net error the same disposition as the one-shot variant', function (string $variant, RetryAction $action) {
    $wire = json_encode(['session_id' => 's1', 'error' => $variant, 'detail' => 'gaze daemon request failed closed'], JSON_THROW_ON_ERROR);
    $exception = DaemonEnvelopeParser::parse($wire);

    expect($exception)->toBeInstanceOf(GazeDaemonException::class);
    if ($exception instanceof GazeDaemonException) {
        expect($exception->daemonVariant()->safetyNetVariant())->toBe($variant)
            ->and($exception->retryDisposition())->toBe($action)
            ->and(GazeRetryPolicy::classify($exception))->toBe($action)
            ->and(GazeRetryPolicy::classify($exception))->toBe(GazeRetryPolicy::classify(snrm_oneShot($variant)));
    }
})->with(array_diff_key(UPSTREAM_SAFETY_NET_DISPOSITIONS, array_flip(SAFETY_NET_VARIANTS_WITHOUT_DAEMON_CASE)));

it('leaves every non-safety-net daemon error on Throw', function (GazeDaemonException $exception) {
    expect($exception->daemonVariant()->safetyNetVariant())->toBeNull()
        ->and($exception->retryDisposition())->toBe(RetryAction::Throw)
        ->and(GazeRetryPolicy::classify($exception))->toBe(RetryAction::Throw);
})->with(function () {
    foreach (DaemonErrorVariant::cases() as $case) {
        if (! str_starts_with($case->name, 'SafetyNet')) {
            yield "base {$case->name}" => [snrm_daemon($case)];
        }
    }
    yield 'transport subclass' => [new GazeDaemonTransportException('broken pipe')];
    yield 'timeout subclass' => [new GazeDaemonTimeoutException('request deadline')];
    yield 'feature-unsupported subclass' => [new GazeDaemonFeatureUnsupportedException];
});

it('routes the daemon\'s own Unknown wire name to Throw, not the safety-net Fail', function () {
    // map_safety_net_error's `Unknown` is written verbatim on the daemon and
    // lands in the generic DaemonErrorVariant::Unknown sink, which stays
    // adopter-owned.
    $exception = DaemonEnvelopeParser::parse('{"session_id":"s1","error":"Unknown","detail":"gaze daemon request failed closed"}');

    expect($exception)->toBeInstanceOf(GazeDaemonException::class);
    if ($exception instanceof GazeDaemonException) {
        expect($exception->daemonVariant())->toBe(DaemonErrorVariant::Unknown)
            ->and(GazeRetryPolicy::classify($exception))->toBe(RetryAction::Throw);
    }
});

it('fails closed on a SafetyNet envelope without its variant sidecar', function () {
    $e = Variant::tryFromStderr('{"error":"SafetyNet","exit":3}', 3)
        ->toException('clean', 3, hash('sha256', ''), '{"error":"SafetyNet","exit":3}');

    expect($e)->toBeInstanceOf(GazeSafetyNetFailureException::class)
        ->and($e->safetyNetVariant())->toBe('Unknown')
        ->and($e->retryDisposition())->toBe(RetryAction::Fail);
});
