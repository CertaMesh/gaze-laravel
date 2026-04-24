<?php

declare(strict_types=1);

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
    }

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', realpath(__DIR__.'/../../policy.toml.example'));
});

it('passes the canary command against the real binary', function () {
    $this->artisan('gaze:canary')
        ->assertExitCode(0)
        ->expectsOutputToContain('PASS');
});
