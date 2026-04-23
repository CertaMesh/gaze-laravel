<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use Naoray\GazeLaravel\Install\BinaryInstaller;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/gaze-laravel-installer-'.bin2hex(random_bytes(6));
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    gl_recursiveRemove($this->tmpDir);
});

function gl_recursiveRemove(string $dir): void
{
    if (! is_dir($dir)) {
        @unlink($dir);

        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        is_dir($path) ? gl_recursiveRemove($path) : @unlink($path);
    }
    @rmdir($dir);
}

function gl_makeEvent(BufferIO $io, string $binDir): Event
{
    $config = new Config(false);
    $config->merge(['config' => ['bin-dir' => $binDir]]);

    $composer = new Composer();
    $composer->setConfig($config);

    return new Event('post-install-cmd', $composer, $io);
}

/** @param  array<string, string>  $files */
function gl_buildFixtureTarGz(string $tmpDir, string $stagingDir, array $files): string
{
    if (is_dir($stagingDir)) {
        gl_recursiveRemove($stagingDir);
    }
    mkdir($stagingDir, 0755, true);
    foreach ($files as $name => $contents) {
        file_put_contents($stagingDir.'/'.$name, $contents);
    }

    $tarPath = $tmpDir.'/pkg.tar';
    if (file_exists($tarPath)) {
        unlink($tarPath);
    }
    if (file_exists($tarPath.'.gz')) {
        unlink($tarPath.'.gz');
    }
    $tar = new \PharData($tarPath);
    foreach (array_keys($files) as $name) {
        $tar->addFile($stagingDir.'/'.$name, $name);
    }
    unset($tar);

    $gzPath = $tarPath.'.gz';
    $tarBytes = file_get_contents($tarPath);
    if ($tarBytes === false) {
        throw new \RuntimeException('could not read fixture tar');
    }
    \Phar::unlinkArchive($tarPath);
    file_put_contents($gzPath, gzencode($tarBytes, 9));

    return $gzPath;
}

it('detects a supported target triple on macOS or Linux', function () {
    $target = BinaryInstaller::detectTarget();

    if ($target === null) {
        test()->markTestSkipped('running on an unsupported platform');
    }

    expect($target)->toMatch('/^(aarch64|x86_64)-(apple-darwin|unknown-linux-gnu)$/');
});

it('returns false from alreadyInstalled when binary is missing', function () {
    expect(BinaryInstaller::alreadyInstalled($this->tmpDir.'/nope', '0.1.0'))->toBeFalse();
});

it('matches alreadyInstalled when version output contains the version', function () {
    $path = $this->tmpDir.'/ghostwriter';
    file_put_contents($path, "#!/bin/sh\necho 'ghostwriter 0.1.0'\n");
    chmod($path, 0755);

    expect(BinaryInstaller::alreadyInstalled($path, '0.1.0'))->toBeTrue()
        ->and(BinaryInstaller::alreadyInstalled($path, '0.2.0'))->toBeFalse();
});

it('accepts a matching sha256 in verifyChecksum', function () {
    $tar = $this->tmpDir.'/payload.tar.gz';
    $contents = 'fake tarball bytes';
    file_put_contents($tar, $contents);
    $sha = hash('sha256', $contents);

    $sums = $this->tmpDir.'/SHA256SUMS';
    file_put_contents(
        $sums,
        "0000000000000000000000000000000000000000000000000000000000000000  other.tar.gz\n"
        ."{$sha} *payload.tar.gz\n",
    );

    BinaryInstaller::verifyChecksum($tar, $sums, 'payload.tar.gz');
    expect($tar)->toBeFile();
});

it('rejects a sha256 mismatch in verifyChecksum', function () {
    $tar = $this->tmpDir.'/payload.tar.gz';
    file_put_contents($tar, 'real bytes');

    $sums = $this->tmpDir.'/SHA256SUMS';
    file_put_contents(
        $sums,
        "0000000000000000000000000000000000000000000000000000000000000000  payload.tar.gz\n",
    );

    BinaryInstaller::verifyChecksum($tar, $sums, 'payload.tar.gz');
})->throws(\RuntimeException::class, 'sha256 mismatch for payload.tar.gz');

it('requires a checksum entry for the asset', function () {
    $tar = $this->tmpDir.'/payload.tar.gz';
    file_put_contents($tar, 'x');

    $sums = $this->tmpDir.'/SHA256SUMS';
    file_put_contents($sums, "# empty\n");

    BinaryInstaller::verifyChecksum($tar, $sums, 'payload.tar.gz');
})->throws(\RuntimeException::class, 'no checksum entry for payload.tar.gz');

it('extracts the ghostwriter file into bin-dir', function () {
    $binDir = $this->tmpDir.'/bin';
    mkdir($binDir, 0755, true);

    $tar = gl_buildFixtureTarGz(
        $this->tmpDir,
        $this->tmpDir.'/pkg',
        ['ghostwriter' => "#!/bin/sh\necho ghostwriter 0.1.0\n"],
    );

    BinaryInstaller::extract($tar, $binDir);

    expect($binDir.'/ghostwriter')->toBeFile()
        ->and(file_get_contents($binDir.'/ghostwriter'))->toContain('ghostwriter');
});

it('honors GAZE_SKIP_BINARY_DOWNLOAD', function () {
    putenv('GAZE_SKIP_BINARY_DOWNLOAD=1');
    try {
        $io = new BufferIO();
        BinaryInstaller::postInstall(gl_makeEvent($io, $this->tmpDir));

        expect($io->getOutput())->toContain('skipping binary download')
            ->and($this->tmpDir.'/ghostwriter')->not->toBeFile();
    } finally {
        putenv('GAZE_SKIP_BINARY_DOWNLOAD');
    }
});

it('refuses a non-HTTPS release base', function () {
    putenv('GAZE_RELEASE_BASE=http://example.com/insecure');
    try {
        $io = new BufferIO();
        BinaryInstaller::postInstall(gl_makeEvent($io, $this->tmpDir));

        expect($io->getOutput())->toContain('non-HTTPS')
            ->and($this->tmpDir.'/ghostwriter')->not->toBeFile();
    } finally {
        putenv('GAZE_RELEASE_BASE');
    }
});

it('parses status and Location header', function () {
    $headers = [
        'HTTP/1.1 302 Found',
        'Server: nginx',
        'Location: https://objects.example.com/foo.tar.gz',
        'Content-Length: 0',
    ];

    [$status, $location] = BinaryInstaller::parseStatusAndLocation($headers);

    expect($status)->toBe(302)
        ->and($location)->toBe('https://objects.example.com/foo.tar.gz');
});

it('keeps the final Location when multiple HTTP status lines appear', function () {
    $headers = [
        'HTTP/1.1 100 Continue',
        'HTTP/1.1 200 OK',
        'Content-Type: application/octet-stream',
    ];

    [$status, $location] = BinaryInstaller::parseStatusAndLocation($headers);

    expect($status)->toBe(200)
        ->and($location)->toBeNull();
});

it('resolves absolute redirect URLs unchanged', function () {
    expect(BinaryInstaller::resolveLocation(
        'https://github.com/x/y/z',
        'https://cdn.example.com/blob',
    ))->toBe('https://cdn.example.com/blob');
});

it('resolves root-relative redirects onto the origin scheme and host', function () {
    expect(BinaryInstaller::resolveLocation(
        'https://github.com/x/y/z',
        '/download/artifact.tar.gz',
    ))->toBe('https://github.com/download/artifact.tar.gz');
});

it('resolves path-relative redirects against the current directory', function () {
    expect(BinaryInstaller::resolveLocation(
        'https://example.com/a/b/c',
        'd.tar.gz',
    ))->toBe('https://example.com/a/b/d.tar.gz');
});

it('short-circuits when the binary is already at the pinned version', function () {
    $binPath = $this->tmpDir.'/ghostwriter';
    $version = BinaryInstaller::PINNED_VERSION;
    file_put_contents($binPath, "#!/bin/sh\necho 'ghostwriter {$version}'\n");
    chmod($binPath, 0755);

    $io = new BufferIO();
    BinaryInstaller::postInstall(gl_makeEvent($io, $this->tmpDir));

    expect($io->getOutput())->toContain("ghostwriter v{$version} already installed")
        ->and($binPath)->toBeFile();
});
