<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Install\Lock\LockGuard;
use Naoray\GazeLaravel\Install\NerLockHeldException;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/gaze-lock-'.bin2hex(random_bytes(6));
    mkdir($this->tmp);
});

afterEach(function () {
    foreach (glob($this->tmp.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->tmp);
});

it('rejects a second holder while the first lock is active', function () {
    $path = $this->tmp.'/install.lock';
    $first = LockGuard::acquire($path);

    expect(fn () => LockGuard::acquire($path))
        ->toThrow(NerLockHeldException::class);

    $first->release();
});

it('keeps the lockfile after release and writes diagnostics', function () {
    $path = $this->tmp.'/install.lock';
    $guard = LockGuard::acquire($path);
    $body = file_get_contents($path);

    $guard->release();

    expect(is_file($path))->toBeTrue();
    expect($body)->toMatch('/^\d+ .+$/');
});
