<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use Illuminate\Support\Facades\Process;

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

it('forwards session-tuning, locale, NER, and safety-net config to gaze daemon', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    config()->set('gaze.daemon.session_idle_timeout_s', 900);
    config()->set('gaze.daemon.session_cap', 250);
    config()->set('gaze.daemon.ner_model_dir', '/opt/gaze/ner');
    config()->set('gaze.daemon.ner_locale', 'de');
    config()->set('gaze.daemon.kiji_distilbert_locales', 'de,fr');
    config()->set('gaze.locale', 'de,en');
    config()->set('gaze.ner_threshold', 0.8);
    config()->set('gaze.safety_net', true);
    config()->set('gaze.safety_net_backend', 'kiji-distilbert');
    config()->set('gaze.kiji_backend', 'ort');
    config()->set('gaze.kiji_distilbert_model_dir', '/opt/kiji/model');
    config()->set('gaze.safety_net_mode', 'strict');

    $this->artisan('gaze:daemon:serve')->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)
            ->toContain('--session-idle-timeout=900')
            ->toContain('--session-cap=250')
            ->toContain('--ner-model-dir=/opt/gaze/ner')
            ->toContain('--ner-locale=de')
            ->toContain('--kiji-distilbert-locales=de,fr')
            ->toContain('--locale=de,en')
            ->toContain('--ner-threshold=0.8')
            ->toContain('--safety-net=openai-filter')
            ->toContain('--safety-net-backend=kiji-distilbert')
            ->toContain('--kiji-backend=ort')
            ->toContain('--kiji-distilbert-model-dir=/opt/kiji/model')
            ->toContain('--safety-net-mode=strict');

        return true;
    });
});

it('lets the new artisan options override session-tuning and pipeline config', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    config()->set('gaze.daemon.session_idle_timeout_s', 900);
    config()->set('gaze.daemon.session_cap', 250);
    config()->set('gaze.locale', 'de');
    config()->set('gaze.ner_threshold', 0.5);

    $this->artisan('gaze:daemon:serve', [
        '--session-idle-timeout' => '120',
        '--session-cap' => '10',
        '--locale' => 'en',
        '--ner-threshold' => '0.95',
    ])->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)
            ->toContain('--session-idle-timeout=120')
            ->toContain('--session-cap=10')
            ->toContain('--locale=en')
            ->toContain('--ner-threshold=0.95')
            ->not->toContain('--session-idle-timeout=900')
            ->not->toContain('--session-cap=250')
            ->not->toContain('--locale=de')
            ->not->toContain('--ner-threshold=0.5');

        return true;
    });
});

it('omits every new flag when the config keys stay null', function () {
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

it('propagates the child exit code on failure', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 137)]);

    $this->artisan('gaze:daemon:serve')->assertExitCode(137);
});
