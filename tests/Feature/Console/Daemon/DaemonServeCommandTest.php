<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\BinaryResolver;

beforeEach(function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );

    config()->set('gaze.daemon.policy_path', '/etc/gaze/policy.toml');
    config()->set('gaze.daemon.idle_timeout_s', 1800);
    config()->set('gaze.daemon.audit_db_path', null);
});

it('forwards config-driven flags to gaze daemon', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $this->artisan('gaze:daemon:serve')->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'daemon',
            '--policy=/etc/gaze/policy.toml',
            '--idle-timeout=1800',
        ]);

        return true;
    });
});

it('lets artisan options override gaze.daemon.* config', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $this->artisan('gaze:daemon:serve', [
        '--policy' => '/tmp/p.toml',
        '--idle-timeout' => '60',
        '--audit-db' => '/var/audit.sqlite',
    ])->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)
            ->toContain('--policy=/tmp/p.toml')
            ->toContain('--idle-timeout=60')
            ->toContain('--audit-db=/var/audit.sqlite');

        return true;
    });
});

it('propagates the child exit code on failure', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 137)]);

    $this->artisan('gaze:daemon:serve')->assertExitCode(137);
});
