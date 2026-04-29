<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Gaze;

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
    }

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', realpath(__DIR__.'/../../policy.toml.example'));
});

it('documents the current rc.3 cross-session behavior for the pinned contract', function () {
    $gaze = $this->app->make(Gaze::class);

    $sessionA = $gaze->clean('alice@example.com');
    $sessionB = $gaze->clean('bob@example.com');

    expect($sessionA->ciphertext->ciphertext())->not->toBe($sessionB->ciphertext->ciphertext());

    // When upstream fixes cross-session token isolation, this legacy behavior
    // should fail in one of three acceptable ways: the foreign token remains
    // unchanged, restore returns an empty string, or GazeUnknownTokenException
    // is thrown. Flip this to a positive assertion once that fix lands.
    $restored = $gaze->restore($sessionA, $sessionB->cleanText);

    expect($restored)->toBe('alice@example.com');
});
