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

it('appends [ner] block to policy without it', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-no-ner.toml');
    $patched = $patcher->buildAppended($body, '/abs/dest/path', null);

    expect($patched)->toContain($body);
    expect($patched)->toContain('[ner]');
    expect($patched)->toMatch('/model_dir\s*=\s*"\/abs\/dest\/path"/');
    expect($patcher->readModelDir($patched))->toBe('/abs/dest/path');
});

it('embeds locale in appended block when provided', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-no-ner.toml');
    $patched = $patcher->buildAppended($body, '/abs/dest/path', 'de');

    expect($patched)->toMatch('/locale\s*=\s*"de"/');
});

it('appended block ends with single trailing newline', function () {
    $patcher = new PolicyTomlPatcher;
    $body = "[session]\nscope=\"persistent\"\n";
    $patched = $patcher->buildAppended($body, '/abs/dest', null);

    expect(substr($patched, -1))->toBe("\n");
    expect(substr($patched, -2, 1))->not->toBe("\n");
});
