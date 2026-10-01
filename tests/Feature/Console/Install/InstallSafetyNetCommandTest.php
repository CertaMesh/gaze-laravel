<?php

declare(strict_types=1);

use CertaMesh\Gaze\Console\Install\InstallSafetyNetCommand;
use CertaMesh\Gaze\Install\SafetyNetConfigurator;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

function isn_bindEnv(string $contents = "APP_ENV=testing\n"): string
{
    $env = sys_get_temp_dir().'/gaze-env-'.bin2hex(random_bytes(6));
    file_put_contents($env, $contents);
    app()->instance(SafetyNetConfigurator::class, new SafetyNetConfigurator($env));

    return $env;
}

function isn_rmEnv(string $env): void
{
    @unlink($env);
    @unlink($env.'.backup');
}

it('errors when non-interactive without --safety-net', function () {
    $this->artisan('gaze:install:safety-net --no-interaction')->assertFailed();
});

it('exits 2 on an unknown backend', function () {
    $env = isn_bindEnv();
    try {
        $this->artisan('gaze:install:safety-net --safety-net=bogus --no-interaction')->assertExitCode(2);
        expect(file_get_contents($env))->toBe("APP_ENV=testing\n");
    } finally {
        isn_rmEnv($env);
    }
});

it('wires opf env keys non-interactively and warns that doctor cannot verify the subprocess (CB4)', function () {
    $env = isn_bindEnv();
    try {
        $this->artisan('gaze:install:safety-net --safety-net=opf --no-interaction')
            ->expectsOutputToContain('opf subprocess')
            ->assertExitCode(0);
        expect(file_get_contents($env))
            ->toContain('GAZE_SAFETY_NET=true')
            ->toContain('GAZE_SAFETY_NET_BACKEND=openai-filter');
    } finally {
        isn_rmEnv($env);
    }
});

it('wires the opf local subprocess command + checkpoint when provided (spec-fix)', function () {
    $env = isn_bindEnv();
    try {
        $this->artisan('gaze:install:safety-net --safety-net=opf --opf-command=/usr/local/bin/opf --opf-checkpoint=/models/opf --no-interaction')
            ->assertExitCode(0);
        expect(file_get_contents($env))
            ->toContain('GAZE_OPENAI_FILTER_COMMAND=/usr/local/bin/opf')
            ->toContain('GAZE_OPENAI_FILTER_CHECKPOINT=/models/opf');
    } finally {
        isn_rmEnv($env);
    }
});

it('rejects the kiji backend removed upstream in gaze 0.15.0 without touching .env', function () {
    $env = isn_bindEnv();
    try {
        $this->artisan('gaze:install:safety-net --safety-net=kiji --no-interaction')
            ->expectsOutputToContain(InstallSafetyNetCommand::KIJI_REMOVED)
            ->assertExitCode(2);
        expect(InstallSafetyNetCommand::KIJI_REMOVED)
            ->toContain('removed upstream in gaze 0.15.0')
            ->toContain('--safety-net=nym');
        expect(file_get_contents($env))->toBe("APP_ENV=testing\n"); // untouched
        expect(is_file($env.'.backup'))->toBeFalse();
    } finally {
        isn_rmEnv($env);
    }
});

it('rejects the removed --kiji-model-dir option', function () {
    $this->artisan('gaze:install:safety-net --safety-net=opf --kiji-model-dir=/models/kiji --no-interaction');
})->throws(RuntimeException::class, 'The "--kiji-model-dir" option does not exist.');

it('--print does not mutate .env', function () {
    $env = isn_bindEnv();
    try {
        $this->artisan('gaze:install:safety-net --safety-net=opf --print --no-interaction')
            ->expectsOutputToContain('GAZE_SAFETY_NET=true')
            ->assertExitCode(0);
        expect(file_get_contents($env))->toBe("APP_ENV=testing\n");
    } finally {
        isn_rmEnv($env);
    }
});

it('wires safety-net with no progress escape sequences when non-interactive', function () {
    $env = isn_bindEnv();
    $command = app()->make(InstallSafetyNetCommand::class);
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    try {
        $exit = $tester->execute(['--safety-net' => 'opf'], ['interactive' => false, 'decorated' => false]);

        expect($exit)->toBe(0);
        expect($tester->getDisplay())->not->toContain("\x1b"); // no spinner control chars in CI
        expect(file_get_contents($env))->toContain('GAZE_SAFETY_NET_BACKEND=openai-filter');
    } finally {
        isn_rmEnv($env);
    }
});

it('interactive choice offers nym and opf and wires opf', function () {
    $env = isn_bindEnv();
    try {
        $this->artisan('gaze:install:safety-net')
            ->expectsChoice('Which safety-net backend?', 'opf', [
                'nym' => 'Nym-small (compiled into the release binary)',
                'opf' => 'OpenAI privacy-filter (Tier 2, needs a safety-net-openai build)',
            ])
            ->assertExitCode(0);
        expect(file_get_contents($env))->toContain('GAZE_SAFETY_NET_BACKEND=openai-filter');
    } finally {
        isn_rmEnv($env);
    }
});
