<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Facades\Gaze;
use Naoray\GazeLaravel\Gaze as GazeService;
use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\RestoredText;
use Naoray\GazeLaravel\Testing\FakeGaze;

it('swaps the bound service for a FakeGaze via Gaze::fake()', function () {
    $fake = Gaze::fake();

    expect($fake)->toBeInstanceOf(FakeGaze::class)
        ->and($this->app->make(GazeService::class))->toBe($fake);
});

it('returns the same fake every call so chained assertions see the same calls', function () {
    $fake = Gaze::fake();

    Gaze::sanitize('Hello Alice', new Context(customerName: 'Alice'));

    expect($fake->sanitizeCalls())->toHaveCount(1);

    Gaze::assertSanitized('Hello Alice');
    Gaze::assertSanitizeCount(1);
});

it('records sanitize calls made via the facade', function () {
    Gaze::fake();

    Gaze::sanitize('foo');
    Gaze::sanitize('bar');

    Gaze::assertSanitizeCount(2);
    Gaze::assertSanitized('foo');
    Gaze::assertSanitized('bar');
});

it('records restore calls made via the facade', function () {
    Gaze::fake();

    Gaze::restore('clean', 'blob');

    Gaze::assertRestored('clean');
    Gaze::assertRestoreCount(1);
});

it('asserts nothing was sanitized when no calls happened', function () {
    Gaze::fake();

    Gaze::assertNothingSanitized();
});

it('invokes custom sanitize and restore handlers when provided', function () {
    Gaze::fake(
        sanitizeHandler: fn () => new GazeSession(
            cleanText: '[FAKED]',
            sessionBlob: '',
            placeholders: [],
            warnings: [],
        ),
        restoreHandler: fn () => new RestoredText(text: '[RESTORED-FAKED]', warnings: []),
    );

    expect(Gaze::sanitize('x')->cleanText)->toBe('[FAKED]');
    expect(Gaze::restore('x', 'y')->text)->toBe('[RESTORED-FAKED]');
});

it('fails the test when assertions are called without fake()', function () {
    try {
        Gaze::assertSanitized();
        throw new \AssertionError('expected assertion failure');
    } catch (\PHPUnit\Framework\AssertionFailedError $e) {
        expect($e->getMessage())->toContain('Gaze::fake() has not been called');
    }
});
