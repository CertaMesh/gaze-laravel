<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Queue;

use Illuminate\Support\Facades\Event;
use Naoray\GazeLaravel\Events\GazeInfraAlert;
use Naoray\GazeLaravel\Exceptions\GazeSafetyNetFailureException;
use Naoray\GazeLaravel\Queue\Contracts\NonRetryable;
use Naoray\GazeLaravel\Queue\Contracts\Retryable;
use Naoray\GazeLaravel\Queue\Contracts\RetryableWithAlert;

final class GazeRetryPolicy
{
    public static function classify(\Throwable $e): RetryAction
    {
        return match (true) {
            $e instanceof GazeSafetyNetFailureException && $e->isNonRetryable() => RetryAction::Fail,
            $e instanceof GazeSafetyNetFailureException && $e->isRetryableWithAlert() => RetryAction::ReleaseWithAlert,
            $e instanceof GazeSafetyNetFailureException && $e->isRetryable() => RetryAction::ReleaseWithBackoff,
            $e instanceof NonRetryable => RetryAction::Fail,
            $e instanceof RetryableWithAlert => RetryAction::ReleaseWithAlert,
            $e instanceof Retryable => RetryAction::ReleaseWithBackoff,
            default => RetryAction::Throw,
        };
    }

    /**
     * PHP cannot type traits in an intersection signature, so runtime checks
     * enforce the documented requirement that the consumer job uses
     * Queueable + InteractsWithQueue.
     */
    public static function dispatch(\Throwable $e, object $job): void
    {
        $action = self::classify($e);

        if ($action === RetryAction::Throw) {
            throw $e;
        }

        $missing = [];
        if (! method_exists($job, 'fail')) {
            $missing[] = 'fail';
        }
        if (! method_exists($job, 'release')) {
            $missing[] = 'release';
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Consumer job is missing required queue method(s): '.implode(', ', $missing).'. Use Queueable and InteractsWithQueue before calling GazeRetryPolicy::dispatch().',
            );
        }

        if ($action === RetryAction::Fail) {
            /** @phpstan-ignore-next-line Method presence is checked above for queue jobs. */
            $job->fail($e);

            return;
        }

        if ($action === RetryAction::ReleaseWithAlert) {
            Event::dispatch(new GazeInfraAlert($e));
        }

        /** @phpstan-ignore-next-line Method presence is checked above for queue jobs. */
        $job->release(self::releaseDelay($job));
    }

    private static function releaseDelay(object $job): int
    {
        $backoff = property_exists($job, 'backoff') ? $job->backoff : null;

        if (is_int($backoff)) {
            return $backoff;
        }

        if (is_array($backoff)) {
            $attempts = method_exists($job, 'attempts') ? $job->attempts() : 1;
            $index = max(0, is_int($attempts) ? $attempts - 1 : 0);
            $delay = $backoff[$index] ?? end($backoff);

            return is_int($delay) ? $delay : 30;
        }

        return 30;
    }
}
