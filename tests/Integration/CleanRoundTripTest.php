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

it('round-trips clean then restore against the real binary', function () {
    $original = 'Hi Alice (alice@example.com), please confirm.';

    $gaze = $this->app->make(Gaze::class);
    $session = $gaze->clean($original);

    expect($session->cleanText)->toContain('Alice')
        ->not->toContain('alice@example.com');

    $restored = $gaze->restore($session, $session->cleanText);

    expect($restored)->toContain('alice@example.com');
});
