<?php

declare(strict_types=1);

use CertaMesh\Gaze\Events\GazeInfraAlert;
use CertaMesh\Gaze\Exceptions\GazeIoException;
use CertaMesh\Gaze\Exceptions\GazePipelineException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetConfigException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetFailureException;
use CertaMesh\Gaze\Exceptions\GazeUnknownTokenException;
use CertaMesh\Gaze\Exceptions\GazeUnsupportedSessionScopeException;
use CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition;
use CertaMesh\Gaze\Queue\Contracts\NonRetryable;
use CertaMesh\Gaze\Queue\Contracts\Retryable;
use CertaMesh\Gaze\Queue\GazeRetryPolicy;
use CertaMesh\Gaze\Queue\RetryAction;
use Illuminate\Support\Facades\Event;

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

it('classifies unknown safety-net variants as Fail', function () {
    // Upstream may ship variants this package does not know yet (Runtime,
    // InvalidOutput, ModelUnavailable, ...). Fail closed rather than retry.
    expect(GazeRetryPolicy::classify(new GazeSafetyNetFailureException('runtime', 3, hash('sha256', ''), 'Runtime')))
        ->toBe(RetryAction::Fail);
});

it('does not mark safety-net failures with static retry marker interfaces', function () {
    // Adopters branch on `$e instanceof NonRetryable` / `Retryable` outside
    // GazeRetryPolicy. A variant-dependent exception must not satisfy static
    // markers, or a retryable Timeout would be misclassified as terminal.
    $retryable = new GazeSafetyNetFailureException('timeout', 3, hash('sha256', ''), 'Timeout');
    $terminal = new GazeSafetyNetFailureException('large', 3, hash('sha256', ''), 'InputTooLarge');

    expect($retryable)->not->toBeInstanceOf(NonRetryable::class)
        ->and($terminal)->not->toBeInstanceOf(Retryable::class)
        ->and($terminal)->not->toBeInstanceOf(NonRetryable::class);
});

it('exposes the variant-dependent disposition via HasRetryDisposition', function (GazeSafetyNetFailureException $exception, RetryAction $action) {
    expect($exception)->toBeInstanceOf(HasRetryDisposition::class)
        ->and($exception->retryDisposition())->toBe($action)
        ->and(GazeRetryPolicy::classify($exception))->toBe($action);
})->with([
    'timeout releases with backoff' => [new GazeSafetyNetFailureException('timeout', 3, hash('sha256', ''), 'Timeout'), RetryAction::ReleaseWithBackoff],
    'suspected leak releases with alert' => [new GazeSafetyNetFailureException('leak', 3, hash('sha256', ''), 'SuspectedLeak'), RetryAction::ReleaseWithAlert],
    'input too large fails' => [new GazeSafetyNetFailureException('large', 3, hash('sha256', ''), 'InputTooLarge'), RetryAction::Fail],
]);

it('dispatches a generic HasRetryDisposition exception without a special case', function () {
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

    $exception = new class('adopter', 0, null) extends RuntimeException implements HasRetryDisposition
    {
        public function retryDisposition(): RetryAction
        {
            return RetryAction::ReleaseWithAlert;
        }
    };

    GazeRetryPolicy::dispatch($exception, $job);

    expect($job->failed)->toBeNull()
        ->and($job->released)->toBe(45);

    Event::assertDispatched(GazeInfraAlert::class);
});
