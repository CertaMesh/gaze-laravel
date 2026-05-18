<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Daemon\DaemonErrorVariant;

it('maps every spec wire variant', function (string $wire, DaemonErrorVariant $expected) {
    expect(DaemonErrorVariant::fromWire($wire))->toBe($expected);
})->with([
    ['JsonMalformed', DaemonErrorVariant::JsonMalformed],
    ['Pipeline', DaemonErrorVariant::Pipeline],
    ['Transport', DaemonErrorVariant::Transport],
    ['Timeout', DaemonErrorVariant::Timeout],
    ['Unavailable', DaemonErrorVariant::Unavailable],
]);

it('falls through to Unknown for unrecognised wire variants', function () {
    expect(DaemonErrorVariant::fromWire('SomeFutureVariant'))->toBe(DaemonErrorVariant::Unknown);
    expect(DaemonErrorVariant::fromWire(''))->toBe(DaemonErrorVariant::Unknown);
    expect(DaemonErrorVariant::fromWire('pipeline'))->toBe(DaemonErrorVariant::Unknown);
});

it('exposes the wire string as the case value', function () {
    expect(DaemonErrorVariant::JsonMalformed->value)->toBe('JsonMalformed');
    expect(DaemonErrorVariant::Unknown->value)->toBe('Unknown');
});
