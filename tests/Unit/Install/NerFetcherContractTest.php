<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Install\NerArtifactSet;
use Naoray\GazeLaravel\Install\NerFetcher;

it('NerFetcher is an interface', function () {
    $reflection = new ReflectionClass(NerFetcher::class);

    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('fetch'))->toBeTrue();
    expect($reflection->hasMethod('verify'))->toBeTrue();
});

it('NerArtifactSet exposes urlBase and files', function () {
    $set = new NerArtifactSet(
        urlBase: 'https://huggingface.co/foo/resolve/abc',
        files: [
            'model.onnx' => ['sha' => 'a'.str_repeat('0', 63), 'size' => 1024],
        ],
    );

    expect($set->urlBase)->toBe('https://huggingface.co/foo/resolve/abc');
    expect($set->totalSize())->toBe(1024);
    expect($set->fileNames())->toBe(['model.onnx']);
});
