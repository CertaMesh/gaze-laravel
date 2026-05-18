<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('exits 0 and lists PIDs when pgrep returns matches', function () {
    Process::fake([
        '*' => Process::result(output: "1234 /usr/local/bin/gaze daemon --policy=/etc/gaze/policy.toml\n5678 /opt/gaze daemon\n", exitCode: 0),
    ]);

    $this->artisan('gaze:daemon:status')->assertExitCode(0);
});

it('exits 1 when pgrep returns no matches', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('gaze:daemon:status')
        ->expectsOutputToContain('no processes found')
        ->assertExitCode(1);
});

it('shells out to pgrep -af gaze daemon', function () {
    Process::fake(['*' => Process::result(output: '1 gaze daemon', exitCode: 0)]);

    $this->artisan('gaze:daemon:status')->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe(['pgrep', '-af', 'gaze daemon']);

        return true;
    });
});
