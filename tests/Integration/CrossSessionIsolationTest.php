<?php

declare(strict_types=1);

use CertaMesh\Gaze\Exceptions\GazeUnknownTokenException;
use CertaMesh\Gaze\Gaze;

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
    }

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', gl_integrationPolicyPath());
});

it('isolates tokens across sessions for the pinned contract', function () {
    $gaze = $this->app->make(Gaze::class);

    $sessionA = $gaze->clean('alice@example.com');
    $sessionB = $gaze->clean('bob@example.com');

    expect($sessionA->ciphertext->ciphertext())->not->toBe($sessionB->ciphertext->ciphertext());

    // Upstream fixed cross-session token isolation: restoring session A's blob
    // against session B's tokens must not leak session A's PII. The pinned
    // binary (v0.11.x) rejects the foreign token outright.
    $gaze->restore($sessionA, $sessionB->cleanText);
})->throws(GazeUnknownTokenException::class);
