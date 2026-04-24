<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Queue;

use Illuminate\Support\Facades\Event;
use Naoray\GazeLaravel\Events\GazeInfraAlert;
use Naoray\GazeLaravel\Queue\Contracts\NonRetryable;
use Naoray\GazeLaravel\Queue\Contracts\Retryable;
use Naoray\GazeLaravel\Queue\Contracts\RetryableWithAlert;

final class GazeRetryPolicy
{
    public static function classify(\Throwable $e): RetryAction
    {
        return match (true) {
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

        if (! method_exists($job, 'fail') || ! method_exists($job, 'release')) {
            throw new \InvalidArgumentException(
                'Consumer jobs must use Queueable and InteractsWithQueue before calling GazeRetryPolicy::dispatch().',
            );
        }

        if ($action === RetryAction::Fail) {
            $job->fail($e);

            return;
        }

        if ($action === RetryAction::ReleaseWithAlert) {
            Event::dispatch(new GazeInfraAlert($e));
        }

        $backoff = property_exists($job, 'backoff') ? $job->backoff : null;
        $job->release(is_int($backoff) ? $backoff : 30);
    }
}
