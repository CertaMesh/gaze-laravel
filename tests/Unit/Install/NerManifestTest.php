<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Install\NerManifest;
use Naoray\GazeLaravel\Install\NerManifestInvalidException;
use Naoray\GazeLaravel\Install\NerVariantUnknownException;

it('resolves the pinned int8 artifact set from SHA256SUMS', function () {
    $set = NerManifest::fromString(gl_nerChecksumFixture())->resolve('int8');

    expect($set->urlBase)->toBe('https://huggingface.co/onnx-community/bert-base-multilingual-cased-ner-hrl-ONNX/resolve/cfe67b1c1c4c91c1b26ac192955fc0971e62d8c8');
    expect($set->fileNames())->toBe([
        'model.onnx',
        'tokenizer.json',
        'tokenizer_config.json',
        'config.json',
        'special_tokens_map.json',
        'vocab.txt',
        'labels.json',
    ]);
    expect($set->files['model.onnx']['sha'])->toBe('1213fdd405d295768b0d41d8214062f2f278f0e3acff6af67d8fd47360d2be0f');
    expect($set->files['model.onnx']['sourceName'] ?? null)->toBe('onnx/model_int8.onnx');
    expect($set->files['model.onnx']['size'])->toBe(178451275);
    expect($set->files['labels.json']['source'] ?? null)->toBe('package');
    expect($set->files['labels.json']['sourceName'] ?? null)->toBe('labels.davlan-mbert.json');
    expect($set->totalSize())->toBeGreaterThan(180_000_000);
});

it('rejects unknown variants', function () {
    $manifest = NerManifest::fromString(gl_nerChecksumFixture());

    expect(fn () => $manifest->resolve('fp32'))
        ->toThrow(NerVariantUnknownException::class);
});

it('rejects duplicate checksum entries', function () {
    $body = str_repeat('a', 64)."  model.onnx\n".str_repeat('b', 64)."  model.onnx\n";

    expect(fn () => NerManifest::fromString($body))
        ->toThrow(NerManifestInvalidException::class);
});

it('rejects path traversal entries', function () {
    $body = str_repeat('a', 64)."  ../model.onnx\n";

    expect(fn () => NerManifest::fromString($body))
        ->toThrow(NerManifestInvalidException::class);
});
