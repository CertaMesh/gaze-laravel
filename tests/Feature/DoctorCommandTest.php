<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\GazeServiceProvider;
use CertaMesh\Gaze\GazeSession;
use CertaMesh\Gaze\Install\BinaryDownloader;
use Illuminate\Support\Facades\Process;

it('reports doctor readiness without deep round-trip', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');

    Process::fake([
        '*' => Process::result(output: "gaze 0.3.0-rc.3\n"),
    ]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('status')
        ->expectsOutputToContain('OK');
});

it('runs the deep round-trip check when requested', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');

    Process::fake([
        '*' => Process::result(output: "gaze 0.3.0-rc.3\n"),
    ]);

    $clean = new GazeSession(
        cleanText: 'Email_1',
        ciphertext: EncryptedBlob::wrap(base64_encode(json_encode([
            'text' => 'doctor@example.com',
        ], JSON_THROW_ON_ERROR))),
        detections: 1,
    );

    $this->bindScriptedGaze($clean, 'doctor@example.com');

    $this->artisan('gaze:doctor', ['--deep' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('deep')
        ->expectsOutputToContain('OK');
});

it('does not warn when rulepacks list only the unified core bundle', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set('gaze.rulepacks', ['core']);

    Process::fake([
        '*' => Process::result(output: "gaze 0.8.0\n"),
    ]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('deprecated');
});

it('warns when gaze.rulepacks lists the deprecated core-extended bundle', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set('gaze.rulepacks', ['core-extended']);

    Process::fake([
        '*' => Process::result(output: "gaze 0.8.0\n"),
    ]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain("rulepack 'core-extended' is deprecated");
});

it('warns when a user policy.toml still bundles core-extended', function () {
    $tmpPolicy = tempnam(sys_get_temp_dir(), 'gaze-doctor-policy-').'.toml';
    file_put_contents($tmpPolicy, <<<'TOML'
[locale]
active = ["de-DE", "en-US"]

[policy.rulepacks]
bundled = ["core", "core-extended"]
TOML);

    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', $tmpPolicy);
    $this->app['config']->set('gaze.rulepacks', []);

    Process::fake([
        '*' => Process::result(output: "gaze 0.8.0\n"),
    ]);

    try {
        $this->artisan('gaze:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain("rulepack 'core-extended' is deprecated");
    } finally {
        @unlink($tmpPolicy);
    }
});

it('warns when policy.toml cannot be parsed as TOML', function () {
    $tmpPolicy = tempnam(sys_get_temp_dir(), 'gaze-doctor-policy-').'.toml';
    file_put_contents($tmpPolicy, "[policy.rulepacks\nbundled = broken");

    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', $tmpPolicy);
    $this->app['config']->set('gaze.rulepacks', []);

    Process::fake([
        '*' => Process::result(output: "gaze 0.8.0\n"),
    ]);

    try {
        $this->artisan('gaze:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('could not be parsed as TOML');
    } finally {
        @unlink($tmpPolicy);
    }
});

it('does not probe proxy feature when gaze.proxy is at defaults', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');

    Process::fake(['*' => Process::result(output: "gaze 0.8.0\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('gaze proxy');
});

it('reports gaze proxy feature available when the binary supports proxy', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set('gaze.proxy.policy_path', '/etc/gaze/proxy.toml');

    Process::fake(function ($process) {
        $command = is_array($process->command) ? $process->command : [];
        if (in_array('proxy', $command, true) && in_array('--help', $command, true)) {
            return Process::result(output: "gaze proxy\n\nUSAGE: gaze proxy <SUBCOMMAND>\n");
        }

        return Process::result(output: "gaze 0.8.0\n");
    });

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('gaze proxy feature available');
});

it('shows no Kiji row when no Kiji config is present', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set('gaze.safety_net', true);
    $this->app['config']->set('gaze.safety_net_backend', 'nym');

    Process::fake(['*' => Process::result(output: "gaze 0.8.1\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('kiji')
        ->doesntExpectOutputToContain('Kiji')
        ->expectsOutputToContain('OK');
});

it('fails when the enabled safety net still selects kiji-distilbert (removed upstream in gaze 0.15.0)', function (array $config) {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set($config);

    Process::fake(['*' => Process::result(output: "gaze 0.8.1\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(1)
        ->expectsOutputToContain('kiji-distilbert removed in gaze 0.15.0')
        ->expectsOutputToContain('Switch GAZE_SAFETY_NET_BACKEND to nym')
        ->expectsOutputToContain('FAIL');
})->with([
    'flat keys (provider-normalized)' => [['gaze.safety_net' => true, 'gaze.safety_net_backend' => 'kiji-distilbert']],
    'nested group' => [['gaze.safety_net' => ['enabled' => true, 'backend' => 'kiji-distilbert']]],
]);

it('warns but passes on leftover Kiji config the adapter now ignores', function (string $key, mixed $value, string $reported) {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set($key, $value);

    Process::fake(['*' => Process::result(output: "gaze 0.8.1\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('kiji config')
        ->expectsOutputToContain("upstream removed the backend in gaze 0.15.0: {$reported}.")
        ->expectsOutputToContain('switch to nym');
})->with([
    'pre-v0.13 flat key' => ['gaze.kiji_backend', 'ort', 'gaze.kiji_backend'],
    'flat model dir' => ['gaze.kiji_distilbert_model_dir', '/var/lib/gaze/models/kiji', 'gaze.kiji_distilbert_model_dir'],
    'daemon locales' => ['gaze.daemon.kiji_distilbert_locales', 'de,fr', 'gaze.daemon.kiji_distilbert_locales'],
    'nested group' => ['gaze.safety_net', ['enabled' => false, 'kiji' => ['backend' => 'ort', 'distilbert_precision' => null]], 'gaze.safety_net.kiji.backend'],
    'selector on a disabled net' => ['gaze.safety_net_backend', 'kiji-distilbert', 'gaze.safety_net.backend=kiji-distilbert (net disabled)'],
]);

it('warns on a published nested kiji group after the provider collapsed it at boot', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    // A v0.13-shaped published config carrying a literal Kiji value, normalized
    // the way a real boot does it: the group collapses to the bool switch, so
    // only the provider's legacy back-fill keeps the value visible to doctor.
    $this->app['config']->set('gaze.safety_net', ['enabled' => false, 'kiji' => ['backend' => 'ort']]);
    (new GazeServiceProvider($this->app))->register();

    expect($this->app['config']->get('gaze.safety_net'))->toBeFalse();

    Process::fake(['*' => Process::result(output: "gaze 0.8.1\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('kiji config')
        ->expectsOutputToContain('gaze.kiji_backend');
});

it('fails on kiji-distilbert regardless of case and whitespace', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set(['gaze.safety_net' => true, 'gaze.safety_net_backend' => ' Kiji-Distilbert']);

    Process::fake(['*' => Process::result(output: "gaze 0.8.1\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(1)
        ->expectsOutputToContain('kiji-distilbert removed in gaze 0.15.0');
});

it('warns but passes on a leftover GAZE_KIJI_* env var', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');

    Process::fake(['*' => Process::result(output: "gaze 0.8.1\n")]);

    // A v0.13-shaped published config reads these env vars into the nested
    // safety_net.kiji group, which the provider collapses at boot.
    putenv('GAZE_KIJI_BACKEND=ort');

    try {
        $this->artisan('gaze:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('kiji config')
            ->expectsOutputToContain('GAZE_KIJI_BACKEND');
    } finally {
        putenv('GAZE_KIJI_BACKEND');
    }
});

it('warns with the cargo install hint when the binary lacks the proxy feature', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set('gaze.proxy.policy_path', '/etc/gaze/proxy.toml');

    Process::fake(function ($process) {
        $command = is_array($process->command) ? $process->command : [];
        if (in_array('proxy', $command, true) && in_array('--help', $command, true)) {
            return Process::result(
                output: '',
                errorOutput: "error: unrecognized subcommand 'proxy'\n",
                exitCode: 2,
            );
        }

        return Process::result(output: "gaze 0.8.0\n");
    });

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('cargo install gaze-cli --features proxy');
});

it('skips the restore-telemetry probe when gaze.restore_telemetry is off', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');

    Process::fake(['*' => Process::result(output: 'gaze '.BinaryDownloader::PINNED_VERSION."\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('restore_telemetry');
});

it('warns when restore_telemetry is on but no audit_db_path is configured', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set('gaze.restore_telemetry', true);
    $this->app['config']->set('gaze.audit_db_path', null);

    Process::fake(['*' => Process::result(output: 'gaze '.BinaryDownloader::PINNED_VERSION."\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('gaze.restore_telemetry is enabled but gaze.audit_db_path');
});

it('warns when restore_telemetry is on but the audit_db_path parent is not writable', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set('gaze.restore_telemetry', true);
    $this->app['config']->set('gaze.audit_db_path', '/nonexistent-gaze-dir-'.bin2hex(random_bytes(4)).'/audit.sqlite');

    Process::fake(['*' => Process::result(output: 'gaze '.BinaryDownloader::PINNED_VERSION."\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('is not writable');
});

it('passes the restore-telemetry probe when on and the audit_db_path parent is writable', function () {
    $dir = sys_get_temp_dir().'/gaze-restore-telemetry-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set('gaze.restore_telemetry', true);
    $this->app['config']->set('gaze.audit_db_path', $dir.'/audit.sqlite');

    Process::fake(['*' => Process::result(output: 'gaze '.BinaryDownloader::PINNED_VERSION."\n")]);

    try {
        $this->artisan('gaze:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('restore_telemetry')
            ->doesntExpectOutputToContain('is not writable');
    } finally {
        @rmdir($dir);
    }
});

it('does not warn about the pin when the binary version matches PINNED_VERSION', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');

    Process::fake(['*' => Process::result(output: 'gaze '.BinaryDownloader::PINNED_VERSION."\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('this package pins')
        ->doesntExpectOutputToContain('pinned version');
});

it('warns with the gaze:install --force hint when the installed binary lags PINNED_VERSION', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    // Guard against a host GAZE_BINARY leaking into the soften branch.
    $this->app['config']->set('gaze.binary', null);

    Process::fake(['*' => Process::result(output: "gaze 0.10.0\n")]);

    // CI exports GAZE_VERSION for the binary-install step; a set GAZE_VERSION
    // legitimately softens the hint, so this test must scrub it to reach the
    // --force branch.
    $previous = getenv('GAZE_VERSION');
    putenv('GAZE_VERSION');
    try {
        $this->artisan('gaze:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('but this package pins v'.BinaryDownloader::PINNED_VERSION)
            ->expectsOutputToContain('php artisan gaze:install --force');
    } finally {
        if ($previous !== false) {
            putenv('GAZE_VERSION='.$previous);
        }
    }
});

it('softens the pin-mismatch message and drops the --force hint when GAZE_BINARY is set', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    // An adopter-set GAZE_BINARY (source build / unsupported platform) opts out
    // of the GitHub-release pin, so the mismatch is expected, not actionable.
    $this->app['config']->set('gaze.binary', '/opt/custom/gaze');

    Process::fake(['*' => Process::result(output: "gaze 0.10.0\n")]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('GAZE_BINARY is set')
        ->doesntExpectOutputToContain('php artisan gaze:install --force');
});

it('softens the pin-mismatch message when GAZE_VERSION pins a different version', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../resources/policy.toml');
    $this->app['config']->set('gaze.binary', null);

    Process::fake(['*' => Process::result(output: "gaze 0.9.5\n")]);

    putenv('GAZE_VERSION=0.9.5');
    try {
        $this->artisan('gaze:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('GAZE_VERSION is set')
            ->doesntExpectOutputToContain('php artisan gaze:install --force');
    } finally {
        putenv('GAZE_VERSION');
    }
});
