<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Exceptions\GazeIoException;
use Naoray\GazeLaravel\Exceptions\GazePolicyOpenException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;
use Naoray\GazeLaravel\Queue\GazeRetryPolicy;
use Naoray\GazeLaravel\Queue\RetryAction;

it('classifies retryable infra failures with alerting', function () {
    expect(GazeRetryPolicy::classify(new GazeIoException('io', 4, hash('sha256', ''))))
        ->toBe(RetryAction::ReleaseWithAlert);
});

it('classifies policy-open as non-retryable', function () {
    expect(GazeRetryPolicy::classify(new GazePolicyOpenException('open', 4, hash('sha256', ''))))
        ->toBe(RetryAction::Fail);
});

it('classifies integrity failures as non-retryable', function () {
    expect(GazeRetryPolicy::classify(new GazeUnknownTokenException('unknown', 3, hash('sha256', ''))))
        ->toBe(RetryAction::Fail);
});
