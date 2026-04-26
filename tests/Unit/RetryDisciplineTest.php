<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Exceptions\GazeBlobExpiredException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidBlobVersionException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidSignatureException;
use Naoray\GazeLaravel\Exceptions\GazeIoException;
use Naoray\GazeLaravel\Exceptions\GazePipelineException;
use Naoray\GazeLaravel\Exceptions\GazePolicyOpenException;
use Naoray\GazeLaravel\Exceptions\GazeSigPipeException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;
use Naoray\GazeLaravel\Queue\GazeRetryPolicy;
use Naoray\GazeLaravel\Queue\RetryAction;

it('classifies retryable infra failures with alerting', function () {
    expect(GazeRetryPolicy::classify(new GazeIoException('io', 4, hash('sha256', ''))))
        ->toBe(RetryAction::ReleaseWithAlert);
});

it('classifies GazeSigPipeException as ReleaseWithAlert', function () {
    expect(GazeRetryPolicy::classify(new GazeSigPipeException('sigpipe', 141, hash('sha256', ''))))
        ->toBe(RetryAction::ReleaseWithAlert);
});

it('classifies policy-open as non-retryable', function () {
    expect(GazeRetryPolicy::classify(new GazePolicyOpenException('open', 4, hash('sha256', ''))))
        ->toBe(RetryAction::Fail);
});

it('classifies unknown-token integrity failures as non-retryable', function () {
    expect(GazeRetryPolicy::classify(new GazeUnknownTokenException('unknown', 3, hash('sha256', ''))))
        ->toBe(RetryAction::Fail);
});

it('classifies blob-expired as non-retryable', function () {
    expect(GazeRetryPolicy::classify(new GazeBlobExpiredException('expired', 3, hash('sha256', ''))))
        ->toBe(RetryAction::Fail);
});

it('classifies invalid blob version as non-retryable', function () {
    expect(GazeRetryPolicy::classify(new GazeInvalidBlobVersionException('bad version', 3, hash('sha256', ''))))
        ->toBe(RetryAction::Fail);
});

it('classifies invalid signature as non-retryable', function () {
    expect(GazeRetryPolicy::classify(new GazeInvalidSignatureException('bad sig', 3, hash('sha256', ''))))
        ->toBe(RetryAction::Fail);
});

it('classifies pipeline as retryable with backoff', function () {
    expect(GazeRetryPolicy::classify(new GazePipelineException('pipeline', 4, hash('sha256', ''))))
        ->toBe(RetryAction::ReleaseWithBackoff);
});
