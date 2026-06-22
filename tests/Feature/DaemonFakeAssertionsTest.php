<?php

declare(strict_types=1);

use CertaMesh\Gaze\Facades\Gaze;
use PHPUnit\Framework\AssertionFailedError;

it('passes assertDaemonCleaned when a daemon call ran', function () {
    Gaze::fake();

    Gaze::daemon()->session('a')->clean('hello alice@example.invalid');

    Gaze::assertDaemonCleaned();
    Gaze::assertDaemonCleaned('a');
    Gaze::assertDaemonCleaned('a', 'hello alice@example.invalid');
});

it('fails assertDaemonCleaned when session or text does not match', function () {
    Gaze::fake();

    Gaze::daemon()->clean('a', 'one');

    expect(fn () => Gaze::assertDaemonCleaned('b'))->toThrow(AssertionFailedError::class);
    expect(fn () => Gaze::assertDaemonCleaned('a', 'two'))->toThrow(AssertionFailedError::class);
});

it('counts daemon calls via assertDaemonCleanCount', function () {
    Gaze::fake();

    Gaze::daemon()->session('a')->clean('one');
    Gaze::daemon()->clean('a', 'two');
    Gaze::daemon()->session('b')->clean('three');

    Gaze::assertDaemonCleanCount(3);
});

it('passes assertNothingDaemonCleaned when no daemon calls ran', function () {
    Gaze::fake();

    Gaze::clean('one-shot only');

    Gaze::assertNothingDaemonCleaned();
});

it('fails assertNothingDaemonCleaned when a daemon call ran', function () {
    Gaze::fake();

    Gaze::daemon()->clean('a', 'leak');

    expect(fn () => Gaze::assertNothingDaemonCleaned())->toThrow(AssertionFailedError::class);
});
