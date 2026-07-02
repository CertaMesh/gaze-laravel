<?php

declare(strict_types=1);

use CertaMesh\Gaze\Facades\Gaze;
use CertaMesh\Gaze\Gaze as GazeService;
use CertaMesh\Gaze\Testing\FakeGaze;
use PHPUnit\Framework\AssertionFailedError;

it('swaps the bound service for a fake', function () {
    $fake = Gaze::fake();

    expect($fake)->toBeInstanceOf(FakeGaze::class)
        ->and($this->app->make(GazeService::class))->toBe($fake);
});

it('records clean calls made through the facade', function () {
    Gaze::fake();

    Gaze::clean('foo');
    Gaze::clean('bar');

    Gaze::assertCleanCount(2);
    Gaze::assertCleaned('foo');
    Gaze::assertCleaned('bar');
});

it('records restore calls made through the facade', function () {
    Gaze::fake();

    $session = Gaze::clean('Hello Alice');
    Gaze::restore($session, $session->cleanText);

    Gaze::assertRestoreCount(1);
});

it('records mask calls made through the facade', function () {
    Gaze::fake();

    Gaze::mask('Hello Alice');
    Gaze::mask('Hello Bob');

    Gaze::assertMaskCount(2);
    Gaze::assertMasked();
    Gaze::assertMasked('Hello Alice');
    Gaze::assertMasked('Hello Bob');
});

it('fails assertMasked when the text does not match', function () {
    Gaze::fake();

    Gaze::mask('Hello Alice');

    expect(fn () => Gaze::assertMasked('Hello Mallory'))->toThrow(AssertionFailedError::class);
});

it('fails assertMasked when mask was never called', function () {
    Gaze::fake();

    Gaze::clean('Hello Alice');

    expect(fn () => Gaze::assertMasked())->toThrow(AssertionFailedError::class);
});

it('fails assertMaskCount on a mismatch', function () {
    Gaze::fake();

    Gaze::mask('Hello Alice');

    expect(fn () => Gaze::assertMaskCount(2))->toThrow(AssertionFailedError::class);
});

it('passes assertNothingMasked when mask was not called', function () {
    Gaze::fake();

    Gaze::clean('Hello Alice');

    Gaze::assertNothingMasked();
});

it('fails assertNothingMasked when a mask call ran', function () {
    Gaze::fake();

    Gaze::mask('Hello Alice');

    expect(fn () => Gaze::assertNothingMasked())->toThrow(AssertionFailedError::class);
});
