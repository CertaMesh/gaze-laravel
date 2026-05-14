<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\BinaryResolver;

beforeEach(function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
});

it('forwards config-driven stop_timeout', function () {
    Process::fake(['*' => Process::result(output: "gaze-proxy stopped\n")]);

    $this->artisan('gaze:proxy:stop')
        ->assertExitCode(0)
        ->expectsOutputToContain('gaze-proxy stopped');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe(['/fake/gaze', 'proxy', 'stop', '--timeout=10s']);

        return true;
    });
});

it('emits --force when the artisan flag is set', function () {
    Process::fake(['*' => Process::result(output: "gaze-proxy stopped\n")]);

    $this->artisan('gaze:proxy:stop', ['--force' => true, '--timeout' => '5s'])
        ->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe(['/fake/gaze', 'proxy', 'stop', '--force', '--timeout=5s']);

        return true;
    });
});

it('omits --force when not requested', function () {
    Process::fake(['*' => Process::result(output: "gaze-proxy stopped\n")]);

    $this->artisan('gaze:proxy:stop')->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->not->toContain('--force');

        return true;
    });
});

it('surfaces upstream non-zero exit', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'gaze-proxy not running', exitCode: 1),
    ]);

    $this->artisan('gaze:proxy:stop')
        ->assertExitCode(1)
        ->expectsOutputToContain('gaze-proxy not running');
});
