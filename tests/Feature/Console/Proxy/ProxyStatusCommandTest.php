<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
});

it('argv is [binary, proxy, status] with no flags', function () {
    Process::fake([
        '*' => Process::result(output: "gaze-proxy running (pid=4242, bind=127.0.0.1:8787)\n"),
    ]);

    $this->artisan('gaze:proxy:status')->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe(['/fake/gaze', 'proxy', 'status']);

        return true;
    });
});

it('exits 0 when the daemon is running', function () {
    Process::fake([
        '*' => Process::result(output: "gaze-proxy running (pid=4242, bind=127.0.0.1:8787)\n  adapters: openai -> https://api.openai.com/\n"),
    ]);

    $this->artisan('gaze:proxy:status')
        ->assertExitCode(0)
        ->expectsOutputToContain('gaze-proxy running');
});

it('exits 1 when the daemon is stopped', function () {
    Process::fake(['*' => Process::result(output: "gaze-proxy not running\n")]);

    $this->artisan('gaze:proxy:status')
        ->assertExitCode(1)
        ->expectsOutputToContain('gaze-proxy not running');
});

it('exits 1 on a stale pidfile (still contains "not running")', function () {
    Process::fake([
        '*' => Process::result(output: "gaze-proxy not running (stale pidfile pid=12345)\n"),
    ]);

    $this->artisan('gaze:proxy:status')
        ->assertExitCode(1)
        ->expectsOutputToContain('stale pidfile');
});
