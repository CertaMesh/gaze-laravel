<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Install\NymBundle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/*
 * gaze:doctor Nym probe (#157). Active only while the enabled safety net
 * selects nym; fails when the bundle directory is configured nowhere, or when
 * gaze would refuse it (missing dir / files, foreign owner, mode != 0700).
 * Ownership is judged as the user running doctor, so failures tell the
 * adopter to run doctor as the PHP-FPM pool user.
 */

beforeEach(function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../../resources/policy.toml');
    $this->app['config']->set('gaze.safety_net', true);
    $this->app['config']->set('gaze.safety_net_backend', 'nym');
    // Hermetic against a GAZE_NYM_MODEL_DIR exported for the integration suite.
    $this->app['config']->set('gaze.nym_model_dir', null);
    $this->previousEnv = gl_stashNymEnv();

    $this->bundle = gl_makeNymBundle();

    Process::fake(['*' => Process::result(output: "gaze 0.15.1\n")]);
});

afterEach(function () {
    gl_restoreNymEnv($this->previousEnv);
    if (isset($this->bundle)) {
        gl_removeNymBundle($this->bundle);
    }
});

it('fails with the setup command when no bundle directory is configured anywhere', function () {
    $this->artisan('gaze:doctor')
        ->assertExitCode(1)
        ->expectsOutputToContain('not configured')
        ->expectsOutputToContain(
            'no Nym bundle directory is configured: gaze fails every clean with "nym model_dir is missing". '
            ."Set GAZE_NYM_MODEL_DIR (gaze.safety_net.nym.model_dir) or the policy's [safety_net.nym] model_dir."
        )
        ->expectsOutputToContain('sudo -u www-data env XDG_DATA_HOME=/srv/gaze /fake/gaze setup --safety-net nym --non-interactive')
        ->expectsOutputToContain('--nym-model-dir=/srv/gaze/gaze/models/nym-small-int8')
        ->expectsOutputToContain('FAIL');
});

it('passes a valid bundle and names the uid it was checked for', function () {
    $this->app['config']->set('gaze.nym_model_dir', $this->bundle);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('OK for '.NymBundle::userLabel(posix_geteuid()))
        ->doesntExpectOutputToContain('refused');
});

it('finds the bundle through each upstream source', function (string $source) {
    $policy = null;
    match ($source) {
        'nested config group' => $this->app['config']->set('gaze.safety_net', [
            'enabled' => true, 'backend' => 'nym', 'nym' => ['model_dir' => $this->bundle],
        ]),
        'process env' => putenv('GAZE_NYM_MODEL_DIR='.$this->bundle),
        'policy' => $this->app['config']->set('gaze.policy_path', $policy = nbp_policyWithModelDir($this->bundle)),
        default => throw new LogicException("unknown source {$source}"),
    };

    try {
        $this->artisan('gaze:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('OK for');
    } finally {
        if ($policy !== null) {
            @unlink($policy);
        }
    }
})->with(['nested config group', 'process env', 'policy']);

it('fails a set-but-empty GAZE_NYM_MODEL_DIR even when the policy names a valid bundle', function (string $via) {
    // Upstream reads the variable with var_os: set but empty wins over the
    // policy and every clean fails with SafetyNetArtifactMissing.
    $policy = nbp_policyWithModelDir($this->bundle);
    $this->app['config']->set('gaze.policy_path', $policy);
    if ($via === 'dotenv ($_ENV)') {
        $_ENV['GAZE_NYM_MODEL_DIR'] = '';
    } else {
        putenv('GAZE_NYM_MODEL_DIR=');
    }

    try {
        $this->artisan('gaze:doctor')
            ->assertExitCode(1)
            ->expectsOutputToContain('GAZE_NYM_MODEL_DIR is empty')
            // One expectation per output line: Laravel lets one write satisfy only one.
            ->expectsOutputToContain(NymBundle::EMPTY_ENV)
            ->doesntExpectOutputToContain('OK for')
            ->expectsOutputToContain('FAIL');
    } finally {
        @unlink($policy);
    }
})->with(['dotenv ($_ENV)', 'process env (getenv)']);

it('fails a bundle directory that does not exist', function () {
    $this->app['config']->set('gaze.nym_model_dir', $this->bundle.'-gone');

    $this->artisan('gaze:doctor')
        ->assertExitCode(1)
        ->expectsOutputToContain("gaze would refuse the Nym bundle at {$this->bundle}-gone (from gaze.safety_net.nym.model_dir)")
        ->expectsOutputToContain('does not exist')
        ->expectsOutputToContain('run doctor as the PHP-FPM pool user')
        ->expectsOutputToContain('FAIL');
});

it('fails a bundle with required files missing', function () {
    $this->app['config']->set('gaze.nym_model_dir', $this->bundle);
    unlink($this->bundle.'/model_int8.onnx');

    $this->artisan('gaze:doctor')
        ->assertExitCode(1)
        ->expectsOutputToContain('required files are missing: model_int8.onnx')
        ->expectsOutputToContain('run doctor as the PHP-FPM pool user')
        ->expectsOutputToContain('FAIL');
});

it('fails a bundle directory whose mode is not 0700', function () {
    $this->app['config']->set('gaze.nym_model_dir', $this->bundle);
    chmod($this->bundle, 0755);

    $this->artisan('gaze:doctor')
        ->assertExitCode(1)
        ->expectsOutputToContain('the directory mode is 0755; gaze requires exactly 0700')
        ->expectsOutputToContain('sudo chown -R www-data '.$this->bundle.' && sudo chmod 700 '.$this->bundle)
        ->expectsOutputToContain('FAIL');
});

it('fails a bundle owned by another uid than the one doctor runs as', function () {
    // Seam: the real owner cannot be changed without root, so the check runs
    // against an injected effective uid instead.
    $runtimeUid = fileowner($this->bundle) + 4242;
    $this->app->instance(NymBundle::class, new NymBundle(euid: $runtimeUid));
    $this->app['config']->set('gaze.nym_model_dir', $this->bundle);

    $this->artisan('gaze:doctor')
        ->assertExitCode(1)
        ->expectsOutputToContain('refused for uid '.$runtimeUid)
        ->expectsOutputToContain('the directory is owned by '.NymBundle::userLabel((int) fileowner($this->bundle)).', but gaze would run as uid '.$runtimeUid)
        ->expectsOutputToContain(
            "These checks ran as uid {$runtimeUid}. gaze enforces them for the user that runs it, "
            .'so run doctor as the PHP-FPM pool user, e.g. sudo -u www-data php artisan gaze:doctor.'
        )
        ->expectsOutputToContain('FAIL');
});

it('probes only while the enabled net selects nym', function () {
    // Same unconfigured state each time; only the selection differs.
    $states = [
        'enabled nym' => [],
        'net disabled, nym selector left over' => ['gaze.safety_net' => false],
        'openai-filter backend' => ['gaze.safety_net_backend' => 'openai-filter'],
        'default backend' => ['gaze.safety_net_backend' => null],
    ];

    $seen = [];
    foreach ($states as $label => $config) {
        $this->app['config']->set(['gaze.safety_net' => true, 'gaze.safety_net_backend' => 'nym', ...$config]);
        $exit = Artisan::call('gaze:doctor');
        $seen[$label] = [$exit, str_contains(Artisan::output(), 'nym bundle')];
    }

    expect($seen)->toBe([
        'enabled nym' => [1, true],
        'net disabled, nym selector left over' => [0, false],
        'openai-filter backend' => [0, false],
        'default backend' => [0, false],
    ]);
});

function nbp_policyWithModelDir(string $modelDir): string
{
    $body = (string) file_get_contents(__DIR__.'/../../../resources/policy.toml');
    $path = tempnam(sys_get_temp_dir(), 'gaze-nym-policy-').'.toml';
    file_put_contents($path, $body."\n[safety_net.nym]\nmodel_dir = \"{$modelDir}\"\n");

    return $path;
}
