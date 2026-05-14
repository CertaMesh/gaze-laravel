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

it('forwards config-driven flags to gaze proxy serve', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('gaze:proxy:serve')->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'proxy',
            'serve',
            '--bind=127.0.0.1:8787',
            '--upstream-openai=https://api.openai.com/',
            '--upstream-anthropic=https://api.anthropic.com/',
            '--upstream-gemini=https://generativelanguage.googleapis.com/',
            '--rulepack=core',
            '--session-ttl=30m',
        ]);

        return true;
    });
});

it('appends --foreground-daemon when the artisan flag is set', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('gaze:proxy:serve', ['--foreground-daemon' => true])->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--foreground-daemon');

        return true;
    });
});

it('lets artisan options override gaze.proxy.* config', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('gaze:proxy:serve', [
        '--bind' => '0.0.0.0:9000',
        '--rulepack' => 'core-ext',
        '--session-ttl' => '60m',
        '--policy' => '/etc/gaze/policy.toml',
    ])->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)
            ->toContain('--bind=0.0.0.0:9000')
            ->toContain('--rulepack=core-ext')
            ->toContain('--session-ttl=60m')
            ->toContain('--policy=/etc/gaze/policy.toml')
            ->not->toContain('--bind=127.0.0.1:8787');

        return true;
    });
});
