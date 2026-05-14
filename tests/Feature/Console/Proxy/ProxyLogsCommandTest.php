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

it('passes through plain log dump without --follow', function () {
    Process::fake([
        '*' => Process::result(output: "2026-05-14T10:30:00Z gaze-proxy accept\n"),
    ]);

    $this->artisan('gaze:proxy:logs')
        ->assertExitCode(0)
        ->expectsOutputToContain('gaze-proxy accept');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe(['/fake/gaze', 'proxy', 'logs']);

        return true;
    });
});

it('forwards --follow verbatim to upstream', function () {
    Process::fake(['*' => Process::result(output: "")]);

    $this->artisan('gaze:proxy:logs', ['--follow' => true])->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe(['/fake/gaze', 'proxy', 'logs', '--follow']);

        return true;
    });
});
