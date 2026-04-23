<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Facades\Gaze;
use Naoray\GazeLaravel\Gaze as GazeService;
use Naoray\GazeLaravel\Testing\FakeGaze;

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
