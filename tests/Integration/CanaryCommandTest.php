<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Integration;

use Naoray\GazeLaravel\Tests\TestCase;

final class CanaryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $binary = getenv('GAZE_BINARY');
        if (! is_string($binary) || $binary === '') {
            $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
        }

        $this->app['config']->set('gaze.binary', $binary);
    }

    public function test_canary_command_passes_against_real_binary(): void
    {
        $this->artisan('gaze:canary')
            ->assertExitCode(0)
            ->expectsOutputToContain('PASS');
    }
}
