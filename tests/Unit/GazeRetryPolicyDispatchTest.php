<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Naoray\GazeLaravel\Events\GazeInfraAlert;
use Naoray\GazeLaravel\Exceptions\GazeIoException;
use Naoray\GazeLaravel\Exceptions\GazePipelineException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;
use Naoray\GazeLaravel\Queue\GazeRetryPolicy;

it('fails non-retryable exceptions immediately', function () {
    $job = new class
    {
        public ?Throwable $failed = null;

        public mixed $released = null;

        public int $backoff = 45;

        public function fail(Throwable $e): void
        {
            $this->failed = $e;
        }

        public function release(int $delay): void
        {
            $this->released = $delay;
        }
    };

    $exception = new GazeUnknownTokenException('unknown', 3, hash('sha256', ''));
    GazeRetryPolicy::dispatch($exception, $job);

    expect($job->failed)->toBe($exception)
        ->and($job->released)->toBeNull();
});

it('releases retryable infra failures and emits an alert', function () {
    Event::fake();

    $job = new class
    {
        public ?Throwable $failed = null;

        public mixed $released = null;

        public int $backoff = 45;

        public function fail(Throwable $e): void
        {
            $this->failed = $e;
        }

        public function release(int $delay): void
        {
            $this->released = $delay;
        }
    };

    $exception = new GazeIoException('io', 4, hash('sha256', ''));
    GazeRetryPolicy::dispatch($exception, $job);

    expect($job->failed)->toBeNull()
        ->and($job->released)->toBe(45);

    Event::assertDispatched(GazeInfraAlert::class);
});

it('releases pipeline failures with backoff and without an infra alert', function () {
    Event::fake();

    $job = new class
    {
        public ?Throwable $failed = null;

        public mixed $released = null;

        public int $backoff = 45;

        public function fail(Throwable $e): void
        {
            $this->failed = $e;
        }

        public function release(int $delay): void
        {
            $this->released = $delay;
        }
    };

    $exception = new GazePipelineException('pipeline', 3, hash('sha256', ''));
    GazeRetryPolicy::dispatch($exception, $job);

    expect($job->failed)->toBeNull()
        ->and($job->released)->toBe(45);

    Event::assertNotDispatched(GazeInfraAlert::class);
});
