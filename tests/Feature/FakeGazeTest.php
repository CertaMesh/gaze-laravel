<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Testing\FakeGaze;

it('satisfies the Gaze type hint', function () {
    $fake = new FakeGaze;
    $this->app->instance(Gaze::class, $fake);

    expect($this->app->make(Gaze::class))->toBeInstanceOf(Gaze::class)
        ->and($this->app->make(Gaze::class))->toBe($fake);
});

it('records clean and restore calls', function () {
    $fake = new FakeGaze;

    $session = $fake->clean('Hello Alice');
    $restored = $fake->restore($session, $session->cleanText);

    expect($fake->cleanCalls())->toHaveCount(1)
        ->and($fake->restoreCalls())->toHaveCount(1)
        ->and($restored)->toBe('Hello Alice');
});
