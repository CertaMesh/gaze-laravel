<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Install\BinaryDownloader;
use CertaMesh\Gaze\Install\BinaryDownloadOptions;
use CertaMesh\Gaze\Install\BinaryDownloadResult;
use CertaMesh\Gaze\Install\BinaryDownloadStatus;
use CertaMesh\Gaze\Install\NerArtifactSet;
use CertaMesh\Gaze\Install\NerFetcher;
use CertaMesh\Gaze\Install\NerInstaller;
use CertaMesh\Gaze\Install\NerManifest;
use CertaMesh\Gaze\Install\PolicyTomlPatcher;
use CertaMesh\Gaze\Install\SafetyNetConfigurator;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\OutputInterface;

beforeEach(function () {
    // Binary step is deterministic + offline: no resolvable binary + a fake
    // downloader that reports AlreadySatisfied.
    app()->instance(BinaryResolver::class, new BinaryResolver(
        explicitPath: '/nonexistent/gaze-'.bin2hex(random_bytes(4)),
        vendorBinPath: '/nonexistent/gaze',
    ));
    app()->instance(BinaryDownloader::class, new class extends BinaryDownloader
    {
        public function install(BinaryDownloadOptions $opts, ?Closure $emit = null): BinaryDownloadResult
        {
            return new BinaryDownloadResult(BinaryDownloadStatus::AlreadySatisfied, $opts->binDir.'/gaze', BinaryDownloader::PINNED_VERSION, 'ok');
        }
    });
});

function ic_bindEnv(string $contents = "APP_ENV=testing\n"): string
{
    $env = sys_get_temp_dir().'/gaze-env-'.bin2hex(random_bytes(6));
    file_put_contents($env, $contents);
    app()->instance(SafetyNetConfigurator::class, new SafetyNetConfigurator($env));

    return $env;
}

function ic_rmEnv(string $env): void
{
    @unlink($env);
    @unlink($env.'.backup');
}

function ic_cleanPublished(): void
{
    @unlink(base_path('policy.toml'));
    @unlink(config_path('gaze.php'));
}

it('runs binary, publishes config/policy, wires safety-net, and gates on a doctor subprocess', function () {
    Process::fake(['*' => Process::result(output: 'OK', exitCode: 0)]);
    $env = ic_bindEnv();

    try {
        $this->artisan('gaze:install --no-interaction --skip-ner --safety-net=opf')
            ->assertExitCode(0);

        expect(file_get_contents($env))->toContain('GAZE_SAFETY_NET_BACKEND=openai-filter');

        // CB4: the gate runs as a real subprocess so it re-reads the written .env.
        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'gaze:doctor');
        });
    } finally {
        ic_rmEnv($env);
        ic_cleanPublished();
    }
});

it('does not clobber an existing policy.toml without --force-policy', function () {
    $policy = base_path('policy.toml');
    file_put_contents($policy, "# hand-edited\n");

    try {
        $this->artisan('gaze:install --no-interaction --skip-binary --skip-ner --skip-safety-net --no-doctor')
            ->expectsOutputToContain('kept existing policy.toml')
            ->assertExitCode(0);

        expect(file_get_contents($policy))->toContain('# hand-edited');
    } finally {
        ic_cleanPublished();
    }
});

it('honours --skip-binary / --skip-ner / --skip-safety-net', function () {
    try {
        $this->artisan('gaze:install --no-interaction --skip-binary --skip-ner --skip-safety-net --no-doctor')
            ->assertExitCode(0);
    } finally {
        ic_cleanPublished();
    }
});

it('runs the final gaze:doctor as a real subprocess, not in-process (CB4)', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    try {
        $this->artisan('gaze:install --no-interaction --skip-binary --skip-ner --skip-safety-net')
            ->assertExitCode(0);

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'gaze:doctor');
        });
    } finally {
        ic_cleanPublished();
    }
});

it('passes confirm-only --yes (not --force) to gaze:install:ner so an installed model is not re-fetched (CB3)', function () {
    $fetcher = new class implements NerFetcher
    {
        public int $fetches = 0;

        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            $this->fetches++;
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return true; // already installed
        }
    };
    app()->instance(NerInstaller::class, new NerInstaller(
        fetcher: $fetcher,
        patcher: new PolicyTomlPatcher,
        manifest: NerManifest::fromString(gl_nerChecksumFixture()),
    ));

    // The umbrella drives gaze:install:ner with the DEFAULT dest; pre-create it
    // and a policy whose [ner] already points there, so --update-policy is a no-op.
    $dest = $this->app->storagePath('app/gaze-ner/davlan-mbert-ner-hrl-int8');
    @mkdir($dest, 0755, true);
    $policy = sys_get_temp_dir().'/gaze-policy-'.bin2hex(random_bytes(6)).'.toml';
    file_put_contents($policy, "[ner]\nmodel_dir = \"{$dest}\"\n");
    app('config')->set('gaze.policy_path', $policy);
    $policyBefore = file_get_contents($policy);

    try {
        $this->artisan('gaze:install --no-interaction --skip-binary --skip-safety-net --no-doctor')
            ->assertExitCode(0);

        expect($fetcher->fetches)->toBe(0);                 // --yes, not --force → no 184MB re-fetch
        expect(file_get_contents($policy))->toBe($policyBefore); // hand-edited [ner] untouched
    } finally {
        @unlink($policy);
        @unlink($policy.'.bak');
        @unlink($dest.'/SHA256SUMS');
        @rmdir($dest);
        ic_cleanPublished();
    }
});

it('rolls back the .env write when the doctor gate fails (CB6)', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]); // doctor FAILS
    $env = ic_bindEnv("APP_ENV=testing\n");

    try {
        $this->artisan('gaze:install --no-interaction --skip-binary --skip-ner --safety-net=opf')
            ->assertExitCode(1);

        // .env restored to its pre-install content (no half-applied wiring left behind).
        expect(file_get_contents($env))->toBe("APP_ENV=testing\n");
    } finally {
        ic_rmEnv($env);
        ic_cleanPublished();
    }
});

it('prints a per-step summary table', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    try {
        $this->artisan('gaze:install --no-interaction --skip-binary --skip-ner --skip-safety-net')
            ->expectsOutputToContain('binary')
            ->expectsOutputToContain('doctor')
            ->assertExitCode(0);
    } finally {
        ic_cleanPublished();
    }
});
