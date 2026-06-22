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

it('forwards config-driven flags to gaze proxy start', function () {
    Process::fake([
        '*' => Process::result(output: "gaze-proxy started (pid=4242, bind=127.0.0.1:8787, log=/tmp/gaze-proxy.log)\n"),
    ]);

    $this->artisan('gaze:proxy:start')
        ->assertExitCode(0)
        ->expectsOutputToContain('gaze-proxy started');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'proxy',
            'start',
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

it('lets artisan options override gaze.proxy.* config', function () {
    Process::fake([
        '*' => Process::result(output: "gaze-proxy started\n"),
    ]);
    $this->app['config']->set('gaze.proxy.policy_path', '/fallback/policy.toml');

    $this->artisan('gaze:proxy:start', [
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
            ->not->toContain('--bind=127.0.0.1:8787')
            ->not->toContain('--policy=/fallback/policy.toml');

        return true;
    });
});

it('omits --policy when neither artisan option nor config supplies one', function () {
    Process::fake([
        '*' => Process::result(output: "gaze-proxy started\n"),
    ]);
    $this->app['config']->set('gaze.proxy.policy_path', null);

    $this->artisan('gaze:proxy:start')->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        foreach ($process->command as $arg) {
            expect($arg)->not->toStartWith('--policy=');
        }

        return true;
    });
});

it('propagates upstream non-zero exit and stderr', function () {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: 'gaze-proxy already running (pid=12345)',
            exitCode: 1,
        ),
    ]);

    $this->artisan('gaze:proxy:start')
        ->assertExitCode(1)
        ->expectsOutputToContain('gaze-proxy already running');
});
