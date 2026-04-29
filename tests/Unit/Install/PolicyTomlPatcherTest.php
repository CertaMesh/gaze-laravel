<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Install\PolicyTomlPatcher;

beforeEach(function () {
    $this->fixtures = __DIR__.'/../../Fixtures/policy';
});

it('detects [ner] block when present', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-with-ner-same-dir.toml');

    expect($patcher->hasNerBlock($body))->toBeTrue();
});

it('reports no [ner] block when absent', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-no-ner.toml');

    expect($patcher->hasNerBlock($body))->toBeFalse();
});

it('reads existing model_dir', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-with-ner-same-dir.toml');

    expect($patcher->readModelDir($body))->toBe('/abs/storage/app/gaze-ner/davlan-mbert-ner-hrl-int8');
});

it('returns null when no [ner].model_dir set', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-no-ner.toml');

    expect($patcher->readModelDir($body))->toBeNull();
});
