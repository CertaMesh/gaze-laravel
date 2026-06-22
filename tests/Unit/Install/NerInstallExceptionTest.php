<?php

declare(strict_types=1);

use CertaMesh\Gaze\Install\NerInstallException;
use CertaMesh\Gaze\Install\NerLockHeldException;
use CertaMesh\Gaze\Install\NerShaMismatchException;
use CertaMesh\Gaze\Install\NerVariantUnknownException;

it('all NER install exceptions extend NerInstallException', function () {
    expect(is_subclass_of(NerVariantUnknownException::class, NerInstallException::class))->toBeTrue();
    expect(is_subclass_of(NerShaMismatchException::class, NerInstallException::class))->toBeTrue();
    expect(is_subclass_of(NerLockHeldException::class, NerInstallException::class))->toBeTrue();
});

it('NerInstallException carries an exit code', function () {
    $e = new NerVariantUnknownException('bad', ['int8']);

    expect($e->exitCode())->toBe(2);
});

it('NerShaMismatchException names the bad file', function () {
    $e = new NerShaMismatchException('model.onnx', 'expected', 'actual');

    expect($e->fileName)->toBe('model.onnx');
    expect($e->getMessage())->toContain('model.onnx');
});
