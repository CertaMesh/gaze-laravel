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

    $composer = new Composer;
    $composer->setConfig($config);

    return new Event('post-install-cmd', $composer, $io);
}

function gl_makeProcessFixture(string $dir, string $name, string $phpBody): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, "#!/usr/bin/env php\n<?php\n{$phpBody}\n");
    chmod($path, 0755);

    return $path;
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
    $tar = new PharData($tarPath);
    foreach (array_keys($files) as $name) {
        $tar->addFile($stagingDir.'/'.$name, $name);
    }
    unset($tar);

    $gzPath = $tarPath.'.gz';
    $tarBytes = file_get_contents($tarPath);
    if ($tarBytes === false) {
        throw new RuntimeException('could not read fixture tar');
    }
    Phar::unlinkArchive($tarPath);
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
    $path = gl_makeProcessFixture(
        $this->tmpDir,
        'gaze',
        "echo 'gaze 0.3.0-rc.3'.PHP_EOL;",
    );

    expect(BinaryInstaller::alreadyInstalled($path, '0.3.0-rc.3'))->toBeTrue()
        ->and(BinaryInstaller::alreadyInstalled($path, '0.3.0'))->toBeTrue()
        ->and(BinaryInstaller::alreadyInstalled($path, '0.4.0'))->toBeFalse();
});

it('returns false from alreadyInstalled when version probe exits non-zero', function () {
    $path = gl_makeProcessFixture(
        $this->tmpDir,
        'gaze',
        "fwrite(STDERR, 'probe failed'.PHP_EOL);\nexit(1);",
    );

    expect(BinaryInstaller::alreadyInstalled($path, '0.3.0'))->toBeFalse();
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
})->throws(RuntimeException::class, 'sha256 mismatch for payload.tar.gz');

it('requires a checksum entry for the asset', function () {
    $tar = $this->tmpDir.'/payload.tar.gz';
    file_put_contents($tar, 'x');

    $sums = $this->tmpDir.'/SHA256SUMS';
    file_put_contents($sums, "# empty\n");

    BinaryInstaller::verifyChecksum($tar, $sums, 'payload.tar.gz');
})->throws(RuntimeException::class, 'no checksum entry for payload.tar.gz');

it('extracts the gaze file into bin-dir', function () {
    $binDir = $this->tmpDir.'/bin';
    mkdir($binDir, 0755, true);

    $tar = gl_buildFixtureTarGz(
        $this->tmpDir,
        $this->tmpDir.'/pkg',
        ['gaze' => "#!/bin/sh\necho gaze 0.3.0-rc.3\n"],
    );

    BinaryInstaller::extract($tar, $binDir);

    expect($binDir.'/gaze')->toBeFile()
        ->and(file_get_contents($binDir.'/gaze'))->toContain('gaze');
});

it('honors GAZE_SKIP_BINARY_DOWNLOAD', function () {
    putenv('GAZE_SKIP_BINARY_DOWNLOAD=1');
    try {
        $io = new BufferIO;
        BinaryInstaller::postInstall(gl_makeEvent($io, $this->tmpDir));

        expect($io->getOutput())->toContain('skipping binary download')
            ->and($this->tmpDir.'/gaze')->not->toBeFile();
    } finally {
        putenv('GAZE_SKIP_BINARY_DOWNLOAD');
    }
});

it('refuses a non-HTTPS release base', function () {
    putenv('GAZE_RELEASE_BASE=http://example.com/insecure');
    try {
        $io = new BufferIO;
        BinaryInstaller::postInstall(gl_makeEvent($io, $this->tmpDir));

        expect($io->getOutput())->toContain('non-HTTPS')
            ->and($this->tmpDir.'/gaze')->not->toBeFile();
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

it('derives github owner/repo from the default release base', function () {
    $base = 'https://github.com/piinuts/gaze/releases/download';

    expect(BinaryInstaller::deriveGithubRepo($base))->toBe('piinuts/gaze');
});

it('derives github owner/repo when the base has a trailing path segment', function () {
    $base = 'https://github.com/piinuts/gaze/releases/download/v0.3.0';

    expect(BinaryInstaller::deriveGithubRepo($base))->toBe('piinuts/gaze');
});

it('returns null for non-github release bases', function () {
    expect(BinaryInstaller::deriveGithubRepo('https://mirror.internal/gaze/releases/download'))->toBeNull()
        ->and(BinaryInstaller::deriveGithubRepo('https://example.com/foo/bar'))->toBeNull()
        ->and(BinaryInstaller::deriveGithubRepo('http://github.com/piinuts/gaze/releases/download'))->toBeNull();
});

it('builds unauthenticated request headers without an Authorization line', function () {
    $headers = BinaryInstaller::buildRequestHeaders(null, 'application/octet-stream');

    expect($headers)->toContain('Accept: application/octet-stream')
        ->and($headers)->toContain('User-Agent: gaze-laravel/'.BinaryInstaller::PINNED_VERSION);

    foreach ($headers as $line) {
        expect(stripos($line, 'Authorization:'))->toBeFalse();
        expect(stripos($line, 'X-GitHub-Api-Version:'))->toBeFalse();
    }
});

it('builds authenticated request headers with Bearer auth and the api version pin', function () {
    $headers = BinaryInstaller::buildRequestHeaders('ghp_testtoken123', 'application/vnd.github+json');

    expect($headers)->toContain('Accept: application/vnd.github+json')
        ->and($headers)->toContain('User-Agent: gaze-laravel/'.BinaryInstaller::PINNED_VERSION)
        ->and($headers)->toContain('Authorization: Bearer ghp_testtoken123')
        ->and($headers)->toContain('X-GitHub-Api-Version: 2022-11-28');

    // GitHub deprecated `Authorization: token <pat>` — verify we are NOT using that form.
    foreach ($headers as $line) {
        expect(str_starts_with($line, 'Authorization: token '))->toBeFalse();
    }
});

it('treats an empty token as unauthenticated when building headers', function () {
    $headers = BinaryInstaller::buildRequestHeaders('', 'application/octet-stream');

    foreach ($headers as $line) {
        expect(stripos($line, 'Authorization:'))->toBeFalse();
    }
});

it('keeps Authorization when redirect stays on the same host', function () {
    $headers = [
        'User-Agent: gaze-laravel/0.3.0',
        'Authorization: Bearer ghp_secret',
        'Accept: application/octet-stream',
    ];

    $result = BinaryInstaller::stripAuthOnCrossHost($headers, 'api.github.com', 'api.github.com');

    expect($result)->toBe($headers);
});

it('strips Authorization when redirect crosses to a different host (S3)', function () {
    $headers = [
        'User-Agent: gaze-laravel/0.3.0',
        'Authorization: Bearer ghp_secret',
        'Accept: application/octet-stream',
    ];

    $result = BinaryInstaller::stripAuthOnCrossHost($headers, 'api.github.com', 'objects.githubusercontent.com');

    expect($result)->toBe([
        'User-Agent: gaze-laravel/0.3.0',
        'Accept: application/octet-stream',
    ]);
});

it('strips Authorization defensively when host parsing fails', function () {
    $headers = [
        'Authorization: Bearer ghp_secret',
        'User-Agent: gaze-laravel/0.3.0',
    ];

    $result = BinaryInstaller::stripAuthOnCrossHost($headers, 'api.github.com', null);

    expect($result)->toBe(['User-Agent: gaze-laravel/0.3.0']);
});

it('extracts asset id pair from a github releases tag JSON payload', function () {
    $json = json_encode([
        'tag_name' => 'v0.3.0',
        'assets' => [
            ['id' => 111, 'name' => 'other-thing'],
            ['id' => 222, 'name' => 'gaze-aarch64-apple-darwin'],
            ['id' => 333, 'name' => 'gaze-aarch64-apple-darwin.sha256'],
            ['id' => 444, 'name' => 'gaze-x86_64-unknown-linux-gnu'],
        ],
    ]);

    [$assetId, $sumsId] = BinaryInstaller::extractAssetIds(
        (string) $json,
        'gaze-aarch64-apple-darwin',
        'v0.3.0',
    );

    expect($assetId)->toBe(222)
        ->and($sumsId)->toBe(333);
});

it('throws when the github releases tag JSON has no assets array', function () {
    BinaryInstaller::extractAssetIds('{"message":"Not Found"}', 'gaze-aarch64-apple-darwin', 'v0.3.0');
})->throws(RuntimeException::class, 'invalid JSON or no assets[]');

it('throws when the asset name is missing from the release', function () {
    $json = (string) json_encode([
        'assets' => [
            ['id' => 1, 'name' => 'gaze-x86_64-unknown-linux-gnu'],
            ['id' => 2, 'name' => 'gaze-x86_64-unknown-linux-gnu.sha256'],
        ],
    ]);

    BinaryInstaller::extractAssetIds($json, 'gaze-aarch64-apple-darwin', 'v0.3.0');
})->throws(RuntimeException::class, 'asset gaze-aarch64-apple-darwin not found');

it('throws when the .sha256 sidecar asset is missing from the release', function () {
    $json = (string) json_encode([
        'assets' => [
            ['id' => 1, 'name' => 'gaze-aarch64-apple-darwin'],
        ],
    ]);

    BinaryInstaller::extractAssetIds($json, 'gaze-aarch64-apple-darwin', 'v0.3.0');
})->throws(RuntimeException::class, 'asset gaze-aarch64-apple-darwin.sha256 not found');

it('short-circuits when the binary is already at the pinned version', function () {
    $binPath = $this->tmpDir.'/gaze';
    $version = BinaryInstaller::PINNED_VERSION;
    file_put_contents($binPath, "#!/bin/sh\necho 'gaze {$version}'\n");
    chmod($binPath, 0755);

    $io = new BufferIO;
    BinaryInstaller::postInstall(gl_makeEvent($io, $this->tmpDir));

    expect($io->getOutput())->toContain("gaze v{$version} already installed")
        ->and($binPath)->toBeFile();
});
