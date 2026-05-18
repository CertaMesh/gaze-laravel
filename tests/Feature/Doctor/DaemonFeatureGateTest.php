<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\BinaryResolver;

beforeEach(function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );

    config()->set('gaze.policy_path', __DIR__.'/../../../resources/policy.toml');
    config()->set('gaze.blob_encryption_key', null);
});

it('skips the daemon probe entirely when gaze.daemon.policy_path is null', function () {
    config()->set('gaze.daemon.policy_path', null);

    Process::fake([
        '*--version*' => Process::result(output: 'gaze 0.9.0', exitCode: 0),
        '*proxy*' => Process::result(output: 'usage: gaze proxy', exitCode: 0),
        '*daemon*' => Process::result(output: 'should-not-run', exitCode: 0),
    ]);

    $output = $this->artisan('gaze:doctor');
    $output->doesntExpectOutputToContain('gaze daemon');
});

it('surfaces the cargo-install hint when the binary lacks the daemon subverb', function () {
    config()->set('gaze.daemon.policy_path', __DIR__.'/../../../resources/policy.toml');

    Process::fake(function ($process) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($cmd, 'daemon --help')) {
            return Process::result(
                output: '',
                errorOutput: "error: unrecognized subcommand 'daemon'",
                exitCode: 2,
            );
        }

        return Process::result(output: 'gaze 0.9.0', exitCode: 0);
    });

    $this->artisan('gaze:doctor')
        ->expectsOutputToContain('cargo install gaze-cli --features daemon');
});

it('reports daemon feature available when the binary recognises the subverb', function () {
    config()->set('gaze.daemon.policy_path', __DIR__.'/../../../resources/policy.toml');

    Process::fake([
        '*daemon --help*' => Process::result(output: 'usage: gaze daemon', exitCode: 0),
        '*' => Process::result(output: 'gaze 0.9.0', exitCode: 0),
    ]);

    $this->artisan('gaze:doctor')
        ->expectsOutputToContain('gaze daemon feature available');
});
