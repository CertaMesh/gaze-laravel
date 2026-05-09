<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Naoray\GazeLaravel\Events\GazeInfraAlert;
use Naoray\GazeLaravel\Exceptions\GazeIoException;
use Naoray\GazeLaravel\Exceptions\GazePipelineException;
use Naoray\GazeLaravel\Exceptions\GazeSafetyNetConfigException;
use Naoray\GazeLaravel\Exceptions\GazeSafetyNetFailureException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;
use Naoray\GazeLaravel\Exceptions\GazeUnsupportedSessionScopeException;
use Naoray\GazeLaravel\Queue\GazeRetryPolicy;
use Naoray\GazeLaravel\Queue\RetryAction;

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

it('names missing queue symbols in the dispatch guard', function () {
    $job = new class
    {
        public function fail(Throwable $e): void {}
    };

    expect(fn () => GazeRetryPolicy::dispatch(new GazePipelineException('pipeline', 3, hash('sha256', '')), $job))
        ->toThrow(InvalidArgumentException::class, 'release');
});

it('uses Laravel array backoff schedules by attempt number', function () {
    Event::fake();

    $job = new class
    {
        public mixed $released = null;

        /** @var list<int> */
        public array $backoff = [30, 60, 120];

        public function attempts(): int
        {
            return 2;
        }

        public function fail(Throwable $e): void {}

        public function release(int $delay): void
        {
            $this->released = $delay;
        }
    };

    GazeRetryPolicy::dispatch(new GazePipelineException('pipeline', 3, hash('sha256', '')), $job);

    expect($job->released)->toBe(60);
});

it('classifies new safety-net and session-scope variants', function (Throwable $exception, RetryAction $action) {
    expect(GazeRetryPolicy::classify($exception))->toBe($action);
})->with([
    'safety net config' => [new GazeSafetyNetConfigException('config', 3, hash('sha256', '')), RetryAction::Fail],
    'safety net timeout' => [new GazeSafetyNetFailureException('timeout', 3, hash('sha256', ''), 'Timeout'), RetryAction::ReleaseWithBackoff],
    'safety net input too large' => [new GazeSafetyNetFailureException('large', 3, hash('sha256', ''), 'InputTooLarge'), RetryAction::Fail],
    'safety net unsupported' => [new GazeSafetyNetFailureException('unsupported', 3, hash('sha256', ''), 'Unsupported'), RetryAction::Fail],
    'safety net weights missing' => [new GazeSafetyNetFailureException('weights', 3, hash('sha256', ''), 'WeightsMissing'), RetryAction::Fail],
    'safety net suspected leak' => [new GazeSafetyNetFailureException('leak', 3, hash('sha256', ''), 'SuspectedLeak'), RetryAction::ReleaseWithAlert],
    'safety net other' => [new GazeSafetyNetFailureException('other', 3, hash('sha256', ''), 'Other'), RetryAction::ReleaseWithBackoff],
    'unsupported session scope' => [new GazeUnsupportedSessionScopeException('scope', 3, hash('sha256', ''), 'global'), RetryAction::Fail],
]);
