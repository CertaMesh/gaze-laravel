<?php

declare(strict_types=1);

use CertaMesh\Gaze\Exceptions\GazeSafetyNetFailureException;
use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\Queue\GazeRetryPolicy;
use CertaMesh\Gaze\Queue\RetryAction;
use CertaMesh\Gaze\Queue\SafetyNetRetryMap;

/*
 * Pins one safety-net `variant` the real binary writes on `gaze clean` against
 * the retry map (#183). `TolerantModeDisabled` is the one a release binary
 * emits without a model install: `--safety-net-mode tolerant` without
 * `GAZE_ALLOW_TOLERANT` in the environment.
 */

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
    }
    if (getenv('GAZE_ALLOW_TOLERANT') !== false) {
        $this->markTestSkipped('GAZE_ALLOW_TOLERANT is set — the binary would accept tolerant mode.');
    }

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', gl_integrationPolicyPath());
    $this->app['config']->set('gaze.safety_net_mode', 'tolerant');
    if (is_array(config('gaze.safety_net'))) {
        $this->app['config']->set('gaze.safety_net.mode', 'tolerant');
    }
});

it('maps the TolerantModeDisabled variant the binary writes to an explicit Fail', function () {
    try {
        $this->app->make(Gaze::class)->clean('Hi Alice (alice@example.com).');
        $this->fail('expected GazeSafetyNetFailureException');
    } catch (GazeSafetyNetFailureException $e) {
        expect($e->safetyNetVariant())->toBe('TolerantModeDisabled')
            ->and(SafetyNetRetryMap::knows($e->safetyNetVariant()))->toBeTrue()
            ->and($e->isNonRetryable())->toBeTrue()
            ->and(GazeRetryPolicy::classify($e))->toBe(RetryAction::Fail);
    }
});
