<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonErrorVariant;

it('maps upstream wire variants (full set pinned in DaemonErrorVariantContractTest)', function (string $wire, DaemonErrorVariant $expected) {
    expect(DaemonErrorVariant::fromWire($wire))->toBe($expected);
})->with([
    ['JsonMalformed', DaemonErrorVariant::JsonMalformed],
    ['Pipeline', DaemonErrorVariant::Pipeline],
    ['ProtocolInvalid', DaemonErrorVariant::ProtocolInvalid],
]);

it('keeps wire names off the adapter cases, which keep their values for BC', function () {
    expect(DaemonErrorVariant::fromWire('Timeout'))->toBe(DaemonErrorVariant::SafetyNetTimeout);
    expect(DaemonErrorVariant::fromWire('Unavailable'))->toBe(DaemonErrorVariant::SafetyNetUnavailable);
    expect(DaemonErrorVariant::fromWire('Transport'))->toBe(DaemonErrorVariant::Unknown);

    expect(DaemonErrorVariant::from('Transport'))->toBe(DaemonErrorVariant::Transport);
    expect(DaemonErrorVariant::from('Timeout'))->toBe(DaemonErrorVariant::Timeout);
    expect(DaemonErrorVariant::from('Unavailable'))->toBe(DaemonErrorVariant::Unavailable);
});

it('falls through to Unknown for unrecognised wire variants', function () {
    expect(DaemonErrorVariant::fromWire('SomeFutureVariant'))->toBe(DaemonErrorVariant::Unknown);
    expect(DaemonErrorVariant::fromWire(''))->toBe(DaemonErrorVariant::Unknown);
    expect(DaemonErrorVariant::fromWire('pipeline'))->toBe(DaemonErrorVariant::Unknown);
});

it('exposes the wire string as the case value', function () {
    expect(DaemonErrorVariant::JsonMalformed->value)->toBe('JsonMalformed');
    expect(DaemonErrorVariant::Unknown->value)->toBe('Unknown');
});
