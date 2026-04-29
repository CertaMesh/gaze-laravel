<?php

declare(strict_types=1);

use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/gaze-laravel-'.bin2hex(random_bytes(6));
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->tmpDir);
});

function gl_makeExecutable(string $dir, string $name): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, "#!/bin/sh\necho stub\n");
    chmod($path, 0755);

    return $path;
}

it('prefers explicit path over vendor bin', function () {
    $explicit = '/usr/local/bin/custom-gaze';
    $vendor = gl_makeExecutable($this->tmpDir, 'gaze');

    $resolver = new BinaryResolver(explicitPath: $explicit, vendorBinPath: $vendor);

    expect($resolver->resolve())->toBe($explicit);
});

it('treats empty explicit path as unset', function () {
    $vendor = gl_makeExecutable($this->tmpDir, 'gaze');

    expect((new BinaryResolver(explicitPath: '', vendorBinPath: $vendor))->resolve())
        ->toBe($vendor);
});

it('uses vendor bin when executable', function () {
    $vendor = gl_makeExecutable($this->tmpDir, 'gaze');

    expect((new BinaryResolver(explicitPath: null, vendorBinPath: $vendor))->resolve())
        ->toBe($vendor);
});

it('finds gaze on PATH via Symfony ExecutableFinder', function () {
    $pathGaze = gl_makeExecutable($this->tmpDir, 'gaze');
    $originalPath = getenv('PATH');
    putenv('PATH='.$this->tmpDir);

    try {
        expect((new BinaryResolver(
            explicitPath: null,
            vendorBinPath: $this->tmpDir.'/does-not-exist',
        ))->resolve())->toBe($pathGaze);
    } finally {
        putenv('PATH='.($originalPath === false ? '' : $originalPath));
    }
});

it('falls through non-executable vendor path', function () {
    $vendor = $this->tmpDir.'/not-executable';
    file_put_contents($vendor, 'stub');

    $resolver = new BinaryResolver(explicitPath: null, vendorBinPath: $vendor);

    try {
        $resolved = $resolver->resolve();
        expect($resolved)->toBeString()->not->toBe($vendor);
    } catch (GazeBinaryMissingException $e) {
        expect($e->getMessage())->toContain('gaze binary not found');
    }
});

it('raises when binary missing everywhere', function () {
    $resolver = new BinaryResolver(
        explicitPath: null,
        vendorBinPath: $this->tmpDir.'/does-not-exist',
    );

    $originalPath = getenv('PATH');
    putenv('PATH='.$this->tmpDir);
    try {
        expect(fn () => $resolver->resolve())
            ->toThrow(GazeBinaryMissingException::class);
    } finally {
        putenv('PATH='.($originalPath === false ? '' : $originalPath));
    }
});

it('returns null from resolveOrNull when binary missing everywhere', function () {
    $resolver = new BinaryResolver(
        explicitPath: null,
        vendorBinPath: $this->tmpDir.'/does-not-exist',
    );

    $originalPath = getenv('PATH');
    putenv('PATH='.$this->tmpDir);
    try {
        expect($resolver->resolveOrNull())->toBeNull();
    } finally {
        putenv('PATH='.($originalPath === false ? '' : $originalPath));
    }
});
