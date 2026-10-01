<?php

declare(strict_types=1);

use CertaMesh\Gaze\Contracts\Gaze as GazeContract;
use CertaMesh\Gaze\Exceptions\GazePolicyConfigDetailException;
use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\SessionScopeGuard;
use Symfony\Component\Process\Process;

/*
 * Pins why an ephemeral session scope is refused pre-flight, both as the
 * `gaze.session_scope` override (#163) and as the policy's `[session] scope`
 * (#182): gaze
 * clean must export the session blob, and upstream forbids exporting an
 * ephemeral session (`Session::export()` → `ExportForbidden`), reported as the
 * generic, Retryable `Pipeline` error. If upstream ever starts exporting
 * ephemeral sessions or reclassifies the error, these fail loudly and
 * SessionScopeGuard needs another look.
 */

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
    }

    $this->binary = $binary;
    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', gl_integrationPolicyPath());
});

/**
 * The shipped policy with its `[session]` block switched to ephemeral.
 */
function sst_ephemeralPolicyPath(): string
{
    $body = (string) file_get_contents(gl_integrationPolicyPath());
    $body = preg_replace('/^scope = "persistent"\R^ttl_secs = \d+$/m', 'scope = "ephemeral"', $body, 1, $count);
    if ($count !== 1) {
        throw new RuntimeException('resources/policy.toml [session] block changed; update sst_ephemeralPolicyPath().');
    }

    $path = tempnam(sys_get_temp_dir(), 'gaze-ephemeral-policy-').'.toml';
    file_put_contents($path, $body);

    return $path;
}

it('the binary rejects --session-scope=ephemeral on clean with the Pipeline error', function () {
    $process = new Process([
        $this->binary, 'clean', '--policy='.gl_integrationPolicyPath(), '--format=json', '--session-scope=ephemeral',
    ]);
    $process->setInput('hi a@b.co');
    $process->run();

    expect($process->getExitCode())->toBe(3)
        ->and(json_decode(trim($process->getErrorOutput()), true))->toBe(['error' => 'Pipeline', 'exit' => 3]);
});

it('Gaze::clean() refuses GAZE_SESSION_SCOPE=ephemeral without spawning the binary', function () {
    $this->app['config']->set('gaze.session_scope', 'ephemeral');

    expect(fn () => $this->app->make(Gaze::class)->clean('hi a@b.co'))
        ->toThrow(GazePolicyConfigDetailException::class, SessionScopeGuard::EPHEMERAL_UNSUPPORTED);
});

it('the binary answers an ephemeral policy scope with the Pipeline error', function () {
    $policy = sst_ephemeralPolicyPath();

    try {
        $process = new Process([$this->binary, 'clean', '--policy='.$policy, '--format=json']);
        $process->setInput('hi a@b.co');
        $process->run();

        expect($process->getExitCode())->toBe(3)
            ->and(json_decode(trim($process->getErrorOutput()), true))->toBe(['error' => 'Pipeline', 'exit' => 3]);
    } finally {
        @unlink($policy);
    }
});

it('Gaze::clean() refuses an ephemeral policy scope pre-flight unless GAZE_SESSION_SCOPE overrides it (#182)', function () {
    $policy = sst_ephemeralPolicyPath();
    $this->app['config']->set('gaze.policy_path', $policy);

    try {
        expect(fn () => $this->app->make(Gaze::class)->clean('hi a@b.co'))
            ->toThrow(GazePolicyConfigDetailException::class, SessionScopeGuard::POLICY_EPHEMERAL_UNSUPPORTED);

        $this->app['config']->set('gaze.session_scope', 'conversation');
        // Gaze::class is an alias; the singleton is held under the contract.
        $this->app->forgetInstance(GazeContract::class);

        $gaze = $this->app->make(Gaze::class);
        $session = $gaze->clean('hi a@b.co');

        expect($session->cleanText)->not->toContain('a@b.co')
            ->and($gaze->restore($session, $session->cleanText))->toBe('hi a@b.co');
    } finally {
        @unlink($policy);
    }
});
