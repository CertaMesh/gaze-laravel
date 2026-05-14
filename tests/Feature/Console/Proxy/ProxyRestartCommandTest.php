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

it('forwards config-driven stop_timeout to restart', function () {
    Process::fake([
        '*' => Process::result(output: "gaze-proxy restarted (pid=4242, bind=127.0.0.1:8787, log=/tmp/gaze-proxy.log)\n"),
    ]);

    $this->artisan('gaze:proxy:restart')
        ->assertExitCode(0)
        ->expectsOutputToContain('gaze-proxy restarted');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe(['/fake/gaze', 'proxy', 'restart', '--timeout=10s']);

        return true;
    });
});

it('forwards --force when supplied', function () {
    Process::fake(['*' => Process::result(output: "gaze-proxy restarted\n")]);

    $this->artisan('gaze:proxy:restart', ['--force' => true, '--timeout' => '20s'])
        ->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe(['/fake/gaze', 'proxy', 'restart', '--force', '--timeout=20s']);

        return true;
    });
});

it('surfaces upstream non-zero exit on restart', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'gaze-proxy restart failed', exitCode: 1),
    ]);

    $this->artisan('gaze:proxy:restart')
        ->assertExitCode(1)
        ->expectsOutputToContain('gaze-proxy restart failed');
});
