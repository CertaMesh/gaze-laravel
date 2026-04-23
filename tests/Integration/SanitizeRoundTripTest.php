<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Gaze;

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
    }

    $this->app['config']->set('gaze.binary', $binary);
});

it('round-trips sanitize then restore against the real binary', function () {
    $original = 'Hi Alice (alice@example.com), please confirm.';

    $gaze = $this->app->make(Gaze::class);

    $session = $gaze->sanitize(
        $original,
        new Context(customerName: 'Alice', customerEmail: 'alice@example.com'),
    );

    expect($session->cleanText)->not->toContain('Alice')
        ->not->toContain('alice@example.com');

    $restored = $gaze->restore($session->cleanText, $session->sessionBlob);

    expect($restored->text)->toContain('Alice')
        ->toContain('alice@example.com');
});
