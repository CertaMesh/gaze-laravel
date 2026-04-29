<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Install\NerInstallException;
use Naoray\GazeLaravel\Install\NerLockHeldException;
use Naoray\GazeLaravel\Install\NerShaMismatchException;
use Naoray\GazeLaravel\Install\NerVariantUnknownException;

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
