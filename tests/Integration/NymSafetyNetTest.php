<?php

declare(strict_types=1);

use CertaMesh\Gaze\Contracts\Gaze as GazeContract;
use CertaMesh\Gaze\Daemon\Contracts\DaemonClientContract;
use CertaMesh\Gaze\Daemon\DaemonManager;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetConfigException;
use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\Install\NymBundle;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/*
 * The first-class Nym config (#157) against the real binary and a real pinned
 * bundle. Opt-in: needs GAZE_BINARY and GAZE_NYM_MODEL_DIR (a bundle from
 * `gaze setup --safety-net nym`, owned by the test user, mode 0700).
 *
 * GAZE_NYM_MODEL_DIR is stripped from the process environment for every test
 * below, so the bundle can only reach gaze through the adapter's
 * `--nym-model-dir` — otherwise the subprocess would inherit the variable and
 * the forwarding would pass vacuously.
 */

const NYM_PLATE_TEXT = 'Das Fahrzeug mit dem Kennzeichen M-AB 1234 wurde abgeschleppt.';

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    $bundle = getenv('GAZE_NYM_MODEL_DIR');
    if (! is_string($binary) || $binary === '' || ! is_string($bundle) || $bundle === '') {
        $this->markTestSkipped('GAZE_BINARY and GAZE_NYM_MODEL_DIR not both set — Nym integration tests skipped.');
    }

    $this->binary = $binary;
    $this->bundle = $bundle;
    $this->savedEnv = [$_ENV['GAZE_NYM_MODEL_DIR'] ?? null, $_SERVER['GAZE_NYM_MODEL_DIR'] ?? null];
    putenv('GAZE_NYM_MODEL_DIR');
    unset($_ENV['GAZE_NYM_MODEL_DIR'], $_SERVER['GAZE_NYM_MODEL_DIR']);

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', gl_integrationPolicyPath());
    $this->app['config']->set('gaze.safety_net', true);
    $this->app['config']->set('gaze.safety_net_backend', 'nym');
    $this->app['config']->set('gaze.nym_model_dir', $bundle);
    $this->app['config']->set('gaze.nym_intra_threads', 2);
    $this->app->forgetInstance(GazeContract::class);
});

afterEach(function () {
    if (! isset($this->bundle)) {
        return;
    }

    putenv('GAZE_NYM_MODEL_DIR='.$this->bundle);
    [$env, $server] = $this->savedEnv;
    if ($env !== null) {
        $_ENV['GAZE_NYM_MODEL_DIR'] = $env;
    }
    if ($server !== null) {
        $_SERVER['GAZE_NYM_MODEL_DIR'] = $server;
    }
});

it('Gaze::clean() forwards gaze.safety_net.nym.* only while the enabled nym net needs them', function () {
    // (a) Why the gating: the binary rejects the knobs with no net activated.
    $process = new Process([
        $this->binary, 'clean', '--policy='.gl_integrationPolicyPath(), '--format=json',
        '--nym-model-dir='.$this->bundle, '--nym-intra-threads=2',
    ]);
    $process->setInput(NYM_PLATE_TEXT);
    $process->run();

    expect($process->getExitCode())->toBe(3)
        ->and(json_decode(trim($process->getErrorOutput()), true))->toBe([
            'error' => 'SafetyNetConfig',
            'exit' => 3,
            'detail' => 'safety-net backend options require --safety-net=<kind> activation',
        ]);

    // (b) A disabled net keeps the leftover bundle dir inert: the clean
    // succeeds, and the rules alone leave the plate raw (control).
    $this->app['config']->set('gaze.safety_net', false);
    $this->app->forgetInstance(GazeContract::class);
    expect($this->app->make(Gaze::class)->clean(NYM_PLATE_TEXT)->cleanText)->toContain('M-AB 1234');

    // (c) The enabled nym net gets the bundle through --nym-model-dir alone.
    $this->app['config']->set('gaze.safety_net', true);
    $this->app->forgetInstance(GazeContract::class);
    $gaze = $this->app->make(Gaze::class);
    $session = $gaze->clean(NYM_PLATE_TEXT);

    expect($session->cleanText)->not->toContain('M-AB 1234')
        ->and($session->cleanText)->toContain('license_plate')
        ->and($gaze->restore($session, $session->cleanText))->toBe(NYM_PLATE_TEXT);
});

it('gaze:doctor pre-empts the missing bundle dir that fails every clean, and passes the real bundle', function () {
    $this->app['config']->set('gaze.nym_model_dir', null);
    $this->app->forgetInstance(GazeContract::class);

    expect(fn () => $this->app->make(Gaze::class)->clean(NYM_PLATE_TEXT))
        ->toThrow(GazeSafetyNetConfigException::class);

    $exit = Artisan::call('gaze:doctor');
    expect(Artisan::output())->toContain('nym bundle')->toContain('not configured')
        ->and($exit)->toBe(1);

    // Fresh Gaze: doctor's upstream-warning probe runs a real clean with the
    // resolved instance, which still holds the missing dir from above.
    $this->app['config']->set('gaze.nym_model_dir', $this->bundle);
    $this->app->forgetInstance(GazeContract::class);

    $exit = Artisan::call('gaze:doctor');
    expect(Artisan::output())->toContain('OK for '.NymBundle::userLabel(posix_geteuid()))
        ->and($exit)->toBe(0);
});

it('Gaze::daemon() loads the bundle from the forwarded --nym-model-dir', function () {
    $this->app['config']->set('gaze.daemon.policy_path', gl_integrationPolicyPath());
    $this->app['config']->set('gaze.daemon.binary_path', $this->binary);
    // Cold start loads the ONNX model before the first answer.
    $this->app['config']->set('gaze.daemon.request_timeout_ms', 60000);

    try {
        $response = $this->app->make(DaemonManager::class)->clean('nym-1', NYM_PLATE_TEXT);

        expect($response->cleanText)->not->toContain('M-AB 1234')
            ->and($response->cleanText)->toContain('license_plate');
    } finally {
        $this->app->make(DaemonClientContract::class)->disconnect();
    }
});
