<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Exceptions\GazeBlobExpiredException;
use Naoray\GazeLaravel\Gaze;

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
    }

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', realpath(__DIR__.'/../../policy.toml.example'));
    $this->app['config']->set('gaze.session_ttl_seconds', 1);
});

it('raises blob-expired once the session ttl elapses', function () {
    $gaze = $this->app->make(Gaze::class);
    $session = $gaze->clean('alice@example.com');

    sleep(2);

    $gaze->restore($session, $session->cleanText);
})->throws(GazeBlobExpiredException::class);
