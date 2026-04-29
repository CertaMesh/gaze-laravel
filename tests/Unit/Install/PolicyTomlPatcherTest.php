<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Install\PolicyTomlPatcher;

beforeEach(function () {
    $this->fixtures = __DIR__.'/../../Fixtures/policy';
    $this->tmp = sys_get_temp_dir().'/gaze-policy-'.bin2hex(random_bytes(6));
    mkdir($this->tmp);
});

afterEach(function () {
    if (! is_dir($this->tmp)) {
        return;
    }

    foreach (glob($this->tmp.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->tmp);
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

it('refuses to replace existing [ner] with different model_dir without force', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-with-ner-different-dir.toml');

    expect(fn () => $patcher->buildReplaced($body, '/new/dest', null, force: false))
        ->toThrow(\Naoray\GazeLaravel\Install\NerPolicyConflictException::class);
});

it('replaces [ner].model_dir when force is true', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-with-ner-different-dir.toml');
    $patched = $patcher->buildReplaced($body, '/new/dest', null, force: true);

    expect($patcher->readModelDir($patched))->toBe('/new/dest');
});

it('preserves locale and threshold when replacing model_dir', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-with-ner-different-dir.toml');
    $patched = $patcher->buildReplaced($body, '/new/dest', null, force: true);

    expect($patched)->toMatch('/locale\s*=\s*"fr"/');
    expect($patched)->toMatch('/threshold\s*=\s*0\.5/');
});

it('is a no-op when [ner].model_dir already matches', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-with-ner-same-dir.toml');
    $patched = $patcher->buildReplaced(
        $body,
        '/abs/storage/app/gaze-ner/davlan-mbert-ner-hrl-int8',
        null,
        force: false,
    );

    expect($patched)->toBe($body);
});

it('produces unified diff on conflict', function () {
    $patcher = new PolicyTomlPatcher;
    $body = file_get_contents($this->fixtures.'/policy-with-ner-different-dir.toml');

    try {
        $patcher->buildReplaced($body, '/new/dest', null, force: false);
        $this->fail('expected NerPolicyConflictException');
    } catch (\Naoray\GazeLaravel\Install\NerPolicyConflictException $e) {
        expect($e->diff)->toContain('-model_dir = "/some/other/path"');
        expect($e->diff)->toContain('+model_dir = "/new/dest"');
    }
});

it('applies an appended [ner] block to disk and writes a backup', function () {
    $patcher = new PolicyTomlPatcher;
    $policy = $this->tmp.'/policy.toml';
    copy($this->fixtures.'/policy-no-ner.toml', $policy);

    $patched = $patcher->apply($policy, '/abs/dest/path', 'de', force: false);

    expect(file_get_contents($policy))->toBe($patched);
    expect(file_get_contents($policy.'.bak'))->toBe(file_get_contents($this->fixtures.'/policy-no-ner.toml'));
    expect($patcher->readModelDir($patched))->toBe('/abs/dest/path');
});

it('does not overwrite an existing policy backup on repeat apply', function () {
    $patcher = new PolicyTomlPatcher;
    $policy = $this->tmp.'/policy.toml';
    copy($this->fixtures.'/policy-no-ner.toml', $policy);

    $patcher->apply($policy, '/first/dest', null, force: false);
    $firstBackup = file_get_contents($policy.'.bak');
    $patcher->apply($policy, '/second/dest', null, force: true);

    expect(file_get_contents($policy.'.bak'))->toBe($firstBackup);
    expect($patcher->readModelDir((string) file_get_contents($policy)))->toBe('/second/dest');
});
