<?php

declare(strict_types=1);

use CertaMesh\Gaze\Console\Install\InstallSafetyNetCommand;
use CertaMesh\Gaze\Install\KijiArtifacts;
use CertaMesh\Gaze\Install\SafetyNetConfigurator;
use Symfony\Component\Console\Tester\CommandTester;

function isn_bindEnv(string $contents = "APP_ENV=testing\n"): string
{
    $env = sys_get_temp_dir().'/gaze-env-'.bin2hex(random_bytes(6));
    file_put_contents($env, $contents);
    app()->instance(SafetyNetConfigurator::class, new SafetyNetConfigurator($env));

    return $env;
}

function isn_validKijiDir(): string
{
    $dir = sys_get_temp_dir().'/gaze-kiji-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    foreach (KijiArtifacts::REQUIRED as $name) {
        file_put_contents($dir.'/'.$name, $name);
    }

    return $dir;
}

function isn_rmEnv(string $env): void
{
    @unlink($env);
    @unlink($env.'.backup');
}

function isn_rmDir(string $dir): void
{
    foreach (KijiArtifacts::REQUIRED as $name) {
        @unlink($dir.'/'.$name);
    }
    @rmdir($dir);
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

it('wires kiji env keys when the model dir holds the pinned artifacts (CB4 happy path)', function () {
    $env = isn_bindEnv();
    $dir = isn_validKijiDir();
    try {
        $this->artisan("gaze:install:safety-net --safety-net=kiji --kiji-model-dir={$dir} --no-interaction")
            ->assertExitCode(0);
        expect(file_get_contents($env))
            ->toContain('GAZE_SAFETY_NET_BACKEND=kiji-distilbert')
            ->toContain('GAZE_KIJI_BACKEND=ort')
            ->toContain("GAZE_KIJI_DISTILBERT_MODEL_DIR={$dir}");
    } finally {
        isn_rmEnv($env);
        isn_rmDir($dir);
    }
});

it('refuses to write .env when the kiji model dir is missing artifacts (CB4 — no poisoned .env)', function () {
    $env = isn_bindEnv();
    $dir = sys_get_temp_dir().'/gaze-kiji-bad-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true); // empty — no artifacts
    try {
        $this->artisan("gaze:install:safety-net --safety-net=kiji --kiji-model-dir={$dir} --no-interaction")
            ->assertFailed();
        expect(file_get_contents($env))->toBe("APP_ENV=testing\n"); // untouched
        expect(is_file($env.'.backup'))->toBeFalse();
    } finally {
        isn_rmEnv($env);
        @rmdir($dir);
    }
});

it('refuses kiji when no model dir is given at all (CB4)', function () {
    $env = isn_bindEnv();
    try {
        $this->artisan('gaze:install:safety-net --safety-net=kiji --no-interaction')->assertFailed();
        expect(file_get_contents($env))->toBe("APP_ENV=testing\n");
    } finally {
        isn_rmEnv($env);
    }
});

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

it('interactive choice picks kiji and wires the model dir', function () {
    $env = isn_bindEnv();
    $dir = isn_validKijiDir();
    try {
        $this->artisan("gaze:install:safety-net --kiji-model-dir={$dir}")
            ->expectsChoice('Which safety-net backend?', 'kiji', [
                'opf' => 'OpenAI privacy-filter (Tier 2)',
                'kiji' => 'Kiji DistilBERT NER (Tier 2.5)',
            ])
            ->assertExitCode(0);
        expect(file_get_contents($env))->toContain("GAZE_KIJI_DISTILBERT_MODEL_DIR={$dir}");
    } finally {
        isn_rmEnv($env);
        isn_rmDir($dir);
    }
});
