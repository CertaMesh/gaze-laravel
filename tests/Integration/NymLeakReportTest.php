<?php

declare(strict_types=1);

use CertaMesh\Gaze\CoverageState;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetFailureException;
use CertaMesh\Gaze\Gaze;

/*
 * #160: the trust state against the real binary and the real Nym net. The
 * leak_report is the same under every mode; only the decision says whether the
 * flagged spans were protected. Needs GAZE_BINARY (gaze >= 0.15.0) and
 * GAZE_TEST_NYM_MODEL_DIR, a verified Nym-small int8 bundle owned by the
 * current user with directory mode 0700 (`gaze setup --safety-net nym`).
 * Input is synthetic: no real person, date or plate.
 */

const NYM_TEST_INPUT = 'Invoice date 1971-05-30, plate B-MW 1234';

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    $nymDir = getenv('GAZE_TEST_NYM_MODEL_DIR');

    if (! is_string($binary) || $binary === '' || ! is_string($nymDir) || $nymDir === '') {
        $this->markTestSkipped('GAZE_BINARY and GAZE_TEST_NYM_MODEL_DIR not both set — Nym leak-report integration skipped.');
    }

    // The shipped policy plus a [safety_net.nym] table: the policy route the
    // safety-net how-to documents, with no env var leaking into other tests.
    $this->nymPolicyPath = sys_get_temp_dir().'/gaze-laravel-nym-policy-'.bin2hex(random_bytes(6)).'.toml';
    file_put_contents(
        $this->nymPolicyPath,
        file_get_contents(gl_integrationPolicyPath())
            ."\n[safety_net.nym]\nmodel_dir = ".json_encode($nymDir, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    );

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', $this->nymPolicyPath);
});

afterEach(function () {
    unset($_ENV['GAZE_ALLOW_TOLERANT']);

    if (isset($this->nymPolicyPath) && is_file($this->nymPolicyPath)) {
        unlink($this->nymPolicyPath);
    }
});

function nymGaze(?string $mode, ?string $fallback = null): Gaze
{
    config()->set('gaze.safety_net', [
        'enabled' => true,
        'backend' => 'nym',
        'mode' => $mode,
        'fallback' => $fallback,
    ]);

    return app(Gaze::class);
}

it('reports protected Nym suspects as Unverified, not Suspect (#160)', function (?string $mode, ?string $fallback, string $marker) {
    $gaze = nymGaze($mode, $fallback);
    $session = $gaze->clean(NYM_TEST_INPUT);

    // The net flagged the spans and the decision protected them.
    expect($session->cleanText)->not->toContain('1971-05-30')
        ->and($session->cleanText)->not->toContain('B-MW 1234')
        ->and($session->cleanText)->toContain($marker)
        ->and($session->leakReport?->suspectCount)->toBeGreaterThan(0)
        ->and($session->leakReport?->actsOnSuspects)->toBeTrue()
        ->and($session->hasSuspectedLeak())->toBeFalse()
        ->and($session->leakReport?->hasResolvedSuspects())->toBeTrue()
        ->and($session->coverageState())->toBe(CoverageState::Unverified);
})->with([
    'default (resolve + redact)' => [null, null, ':Custom:date_1>'],
    'resolve + strict fallback' => ['resolve', 'strict', ':Custom:license_plate_1>'],
    'redact' => ['redact', null, '[REDACTED:custom:date]'],
]);

it('restores the spans the default resolve decision tokenized', function () {
    $gaze = nymGaze(null);
    $session = $gaze->clean(NYM_TEST_INPUT);

    expect($gaze->restore($session, $session->cleanText))->toBe(NYM_TEST_INPUT);
});

it('reports Suspect when tolerant ships the flagged spans raw', function () {
    $_ENV['GAZE_ALLOW_TOLERANT'] = '1';

    $session = nymGaze('tolerant')->clean(NYM_TEST_INPUT);

    expect($session->cleanText)->toContain('1971-05-30')
        ->and($session->leakReport?->actsOnSuspects)->toBeFalse()
        ->and($session->hasSuspectedLeak())->toBeTrue()
        ->and($session->coverageState())->toBe(CoverageState::Suspect);
});

it('refuses under strict instead of returning a report', function () {
    try {
        nymGaze('strict')->clean(NYM_TEST_INPUT);
        $this->fail('strict mode returned a session for a flagged span');
    } catch (GazeSafetyNetFailureException $e) {
        expect($e->safetyNetVariant())->toBe('SuspectedLeak');
    }
});
