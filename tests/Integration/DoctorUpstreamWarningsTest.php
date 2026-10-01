<?php

declare(strict_types=1);

use CertaMesh\Gaze\Install\BinaryDownloader;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/*
 * #159 against the real binary: gaze >= 0.15 prints its policy warnings on
 * stderr only when a clean succeeds, and gaze:doctor's probe must surface
 * them. If upstream renames or drops the `warning:` prefix, this fails
 * loudly and the probe needs another look.
 */

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');

        return;
    }

    $versionProcess = new Process([$binary, '--version']);
    $versionProcess->run();
    $version = BinaryDownloader::parseVersion($versionProcess->getOutput()) ?? '';
    if (version_compare($version, '0.15.0', '<')) {
        $this->markTestSkipped("gaze v{$version} predates the success-path policy warnings (0.15.0).");
    }

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', gl_integrationPolicyPath());
    $this->app['config']->set('gaze.rulepacks', null);
});

/**
 * The shipped policy with its fall-through rule switched back to preserve.
 */
function duw_preservePolicyPath(): string
{
    $body = (string) file_get_contents(gl_integrationPolicyPath());
    $body = preg_replace('/^kind = "default"\R^action = "tokenize"$/m', "kind = \"default\"\naction = \"preserve\"", $body, 1, $count);
    if ($count !== 1) {
        throw new RuntimeException('resources/policy.toml default rule changed; update duw_preservePolicyPath().');
    }

    $path = tempnam(sys_get_temp_dir(), 'gaze-preserve-policy-').'.toml';
    file_put_contents($path, $body);

    return $path;
}

it('surfaces the 0.15 preserve warning for a policy that preserves by default', function () {
    $policy = duw_preservePolicyPath();
    $this->app['config']->set('gaze.policy_path', $policy);

    try {
        $exit = Artisan::call('gaze:doctor');
        $output = Artisan::output();
    } finally {
        @unlink($policy);
    }

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/upstream warnings\s.*1/')
        ->toMatch('/warning: policy preserves \d+ detected class(es)? without a reachable class rule: .+; values can reach the model raw\./')
        ->toContain('Set the default rule to action = "tokenize"')
        // Upstream's per-class line replaced the static preserve-default row.
        ->not->toContain('policy default')
        ->not->toContain('gaze doctor probe')
        ->toMatch('/status\s.*OK/');
});

it('reports no upstream warning for the shipped policy', function () {
    $exit = Artisan::call('gaze:doctor');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/upstream warnings\s.*none/')
        ->not->toContain('warning: ')
        ->not->toContain('notice: ')
        ->toMatch('/status\s.*OK/');
});
