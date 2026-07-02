<?php

declare(strict_types=1);

use CertaMesh\Gaze\Install\NerInstallException;
use CertaMesh\Gaze\Install\NerLockHeldException;
use CertaMesh\Gaze\Install\NerShaMismatchException;
use CertaMesh\Gaze\Install\NerVariantUnknownException;

it('all NER install exceptions extend NerInstallException', function () {
    expect(NerVariantUnknownException::class)->toExtend(NerInstallException::class);
    expect(NerShaMismatchException::class)->toExtend(NerInstallException::class);
    expect(NerLockHeldException::class)->toExtend(NerInstallException::class);
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
