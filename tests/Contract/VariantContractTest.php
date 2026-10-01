<?php

declare(strict_types=1);

use CertaMesh\Gaze\Tests\Fixtures\UpstreamErrorNames;
use CertaMesh\Gaze\Variant;

/**
 * Source-of-truth fixture mirrored from upstream `crates/gaze-cli/src/error.rs`
 * for gaze v0.15.1. Each row pins one upstream `CliError` variant:
 *   - 0: enum case name on the PHP side
 *   - 1: exit bucket the upstream binary returns (`exit_code()`)
 *   - 2: minimal `{error, exit, ...}` JSON shape upstream emits on stderr
 *
 * Element 2's `error` field is the variant_name() upstream actually writes — note
 * the collapse where `PolicyConfig` and `PolicyConfigDetail` share the same wire
 * name and are disambiguated only by presence of the `detail` sidecar. The sidecar
 * fields on `AuditPurgeIso8601` (`input`) and `UnknownToken` (`token`) are also
 * preserved so we round-trip the realistic shape rather than a stripped one.
 *
 * The dataset key is the case name so test failure messages print the variant
 * (`with data set "PolicyConfigDetail"`) instead of an opaque positional index.
 */
const UPSTREAM_VARIANTS = [
    'StdinParse' => ['StdinParse', 1, ['error' => 'StdinParse', 'exit' => 1]],
    'EmptyInput' => ['EmptyInput', 1, ['error' => 'EmptyInput', 'exit' => 1]],
    'InputTooLarge' => ['InputTooLarge', 1, ['error' => 'InputTooLarge', 'exit' => 1]],
    'InvalidEncoding' => ['InvalidEncoding', 1, ['error' => 'InvalidEncoding', 'exit' => 1]],
    'PolicyConfig' => ['PolicyConfig', 2, ['error' => 'PolicyConfig', 'exit' => 2]],
    'PolicyConfigDetail' => ['PolicyConfigDetail', 2, ['error' => 'PolicyConfig', 'exit' => 2, 'detail' => 'unknown key `[[detector]]`']],
    'PolicySchemaUnsupported' => ['PolicySchemaUnsupported', 2, ['error' => 'PolicySchemaUnsupported', 'exit' => 2, 'found' => '9.9.0', 'supported' => '0.1.']],
    'SafetyNetConfig' => ['SafetyNetConfig', 3, ['error' => 'SafetyNetConfig', 'exit' => 3, 'detail' => 'openai filter config missing']],
    'SafetyNetUsage' => ['SafetyNetUsage', 2, ['error' => 'SafetyNetUsage', 'exit' => 2, 'detail' => '--safety-net-backend requires exactly one --safety-net value']],
    'SafetyNet' => ['SafetyNet', 3, ['error' => 'SafetyNet', 'exit' => 3, 'variant' => 'Timeout']],
    'SafetyNetArtifactMissing' => ['SafetyNetArtifactMissing', 2, ['error' => 'SafetyNetArtifactMissing', 'exit' => 2, 'backend' => 'nym', 'path' => '<missing:SHA256SUMS> (install via gaze setup --safety-net nym)']],
    'AuditPurgeIso8601' => ['AuditPurgeIso8601', 2, ['error' => 'AuditPurgeIso8601', 'exit' => 2, 'input' => 'not-a-date']],
    'UnknownToken' => ['UnknownToken', 3, ['error' => 'UnknownToken', 'exit' => 3, 'token' => 'gz1_abc']],
    'InvalidSignature' => ['InvalidSignature', 3, ['error' => 'InvalidSignature', 'exit' => 3]],
    'InvalidBlobVersion' => ['InvalidBlobVersion', 3, ['error' => 'InvalidBlobVersion', 'exit' => 3]],
    'BlobExpired' => ['BlobExpired', 3, ['error' => 'BlobExpired', 'exit' => 3]],
    'Pipeline' => ['Pipeline', 3, ['error' => 'Pipeline', 'exit' => 3]],
    'Io' => ['Io', 4, ['error' => 'Io', 'exit' => 4]],
    'PolicyOpen' => ['PolicyOpen', 4, ['error' => 'PolicyOpen', 'exit' => 4]],
];

/*
 * Retired variants (kept, deprecated, for BC) and the upstream wire names
 * deliberately left unmapped live in UpstreamErrorNames, shared with the
 * opt-in UpstreamErrorDriftTest that checks them against upstream source.
 */

it('upstream variant exists as a PHP enum case', function (string $name, int $exit, array $wirePayload) {
    $cases = array_map(fn (Variant $v) => $v->name, Variant::cases());

    expect($cases)->toContain($name);
})->with(UPSTREAM_VARIANTS);

it('PHP exit bucket matches upstream exit code', function (string $name, int $exit, array $wirePayload) {
    $variant = constant(Variant::class.'::'.$name);

    expect($variant->exitBucket())->toBe($exit);
})->with(UPSTREAM_VARIANTS);

it('tryFromStderr round-trips the upstream wire shape', function (string $name, int $exit, array $wirePayload) {
    $stderr = json_encode($wirePayload, JSON_THROW_ON_ERROR);
    $expected = constant(Variant::class.'::'.$name);

    expect(Variant::tryFromStderr($stderr, $exit))->toBe($expected);
})->with(UPSTREAM_VARIANTS);

it('PHP enum has no variants beyond the upstream set (catches reverse drift)', function () {
    $expectedNames = array_map(fn (array $row) => $row[0], array_values(UPSTREAM_VARIANTS));
    $actualNames = array_map(fn (Variant $v) => $v->name, Variant::cases());

    expect(array_values(array_diff($actualNames, $expectedNames, UpstreamErrorNames::RETIRED_VARIANTS, UpstreamErrorNames::ADAPTER_VARIANTS)))->toBe([]);
});

it('keeps the adapter-made SigPipe on exit 141 and parseable by name', function () {
    expect(Variant::SigPipe->exitBucket())->toBe(141)
        ->and(Variant::tryFromStderr('{"error":"SigPipe","exit":141}', 141))->toBe(Variant::SigPipe);
});

it('has no case for the deliberately unmapped upstream wire names', function () {
    foreach (UpstreamErrorNames::UNMAPPED_VARIANTS as $wire) {
        expect(Variant::tryFrom($wire))->toBeNull("wire name {$wire}");
    }
});

it('keeps retired upstream variants parseable for older binaries', function () {
    $stderr = json_encode(['error' => 'UnsupportedSessionScope', 'exit' => 3, 'variant' => 'global'], JSON_THROW_ON_ERROR);

    expect(Variant::tryFromStderr($stderr, 3))->toBe(Variant::UnsupportedSessionScope)
        ->and(Variant::UnsupportedSessionScope->exitBucket())->toBe(3);
});

it('maps the exit-2 SafetyNetConfig shape (gaze >= 0.15 Nym setup errors)', function () {
    $stderr = json_encode(['error' => 'SafetyNetConfig', 'exit' => 2, 'detail' => 'nym bundle checksum mismatch'], JSON_THROW_ON_ERROR);

    expect(Variant::tryFromStderr($stderr, 2))->toBe(Variant::SafetyNetConfig);
});
