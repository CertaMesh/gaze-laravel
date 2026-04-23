<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Install;

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use Naoray\GazeLaravel\Install\BinaryInstaller;
use PHPUnit\Framework\TestCase;

final class BinaryInstallerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/gaze-laravel-installer-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->tmpDir);
        parent::tearDown();
    }

    public function test_detect_target_returns_supported_triple_on_macos_or_linux(): void
    {
        $target = BinaryInstaller::detectTarget();

        if ($target === null) {
            self::markTestSkipped('running on an unsupported platform');
        }

        self::assertMatchesRegularExpression(
            '/^(aarch64|x86_64)-(apple-darwin|unknown-linux-gnu)$/',
            $target,
        );
    }

    public function test_already_installed_is_false_for_missing_binary(): void
    {
        self::assertFalse(BinaryInstaller::alreadyInstalled(
            $this->tmpDir.'/nope',
            '0.1.0',
        ));
    }

    public function test_already_installed_matches_when_version_output_contains_version(): void
    {
        $path = $this->tmpDir.'/ghostwriter';
        file_put_contents($path, "#!/bin/sh\necho 'ghostwriter 0.1.0'\n");
        chmod($path, 0755);

        self::assertTrue(BinaryInstaller::alreadyInstalled($path, '0.1.0'));
        self::assertFalse(BinaryInstaller::alreadyInstalled($path, '0.2.0'));
    }

    public function test_verify_checksum_accepts_matching_sha256(): void
    {
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
        self::assertFileExists($tar);
    }

    public function test_verify_checksum_rejects_mismatch(): void
    {
        $tar = $this->tmpDir.'/payload.tar.gz';
        file_put_contents($tar, 'real bytes');

        $sums = $this->tmpDir.'/SHA256SUMS';
        file_put_contents(
            $sums,
            "0000000000000000000000000000000000000000000000000000000000000000  payload.tar.gz\n",
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sha256 mismatch for payload.tar.gz');
        BinaryInstaller::verifyChecksum($tar, $sums, 'payload.tar.gz');
    }

    public function test_verify_checksum_requires_entry_for_asset(): void
    {
        $tar = $this->tmpDir.'/payload.tar.gz';
        file_put_contents($tar, 'x');

        $sums = $this->tmpDir.'/SHA256SUMS';
        file_put_contents($sums, "# empty\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no checksum entry for payload.tar.gz');
        BinaryInstaller::verifyChecksum($tar, $sums, 'payload.tar.gz');
    }

    public function test_extract_places_ghostwriter_into_bin_dir(): void
    {
        $binDir = $this->tmpDir.'/bin';
        mkdir($binDir, 0755, true);

        $tar = $this->buildFixtureTarGz(
            $this->tmpDir.'/pkg',
            ['ghostwriter' => "#!/bin/sh\necho ghostwriter 0.1.0\n"],
        );

        BinaryInstaller::extract($tar, $binDir);

        self::assertFileExists($binDir.'/ghostwriter');
        self::assertStringContainsString('ghostwriter', file_get_contents($binDir.'/ghostwriter') ?: '');
    }

    public function test_post_install_honors_skip_env(): void
    {
        putenv('GAZE_SKIP_BINARY_DOWNLOAD=1');
        try {
            $io = new BufferIO();
            $event = $this->makeEvent($io, $this->tmpDir);

            BinaryInstaller::postInstall($event);

            self::assertStringContainsString('skipping binary download', $io->getOutput());
            self::assertFileDoesNotExist($this->tmpDir.'/ghostwriter');
        } finally {
            putenv('GAZE_SKIP_BINARY_DOWNLOAD');
        }
    }

    public function test_post_install_refuses_non_https_release_base(): void
    {
        putenv('GAZE_RELEASE_BASE=http://example.com/insecure');
        try {
            $io = new BufferIO();
            $event = $this->makeEvent($io, $this->tmpDir);

            BinaryInstaller::postInstall($event);

            self::assertStringContainsString('non-HTTPS', $io->getOutput());
            self::assertFileDoesNotExist($this->tmpDir.'/ghostwriter');
        } finally {
            putenv('GAZE_RELEASE_BASE');
        }
    }

    public function test_post_install_short_circuits_when_binary_already_at_pinned_version(): void
    {
        $binPath = $this->tmpDir.'/ghostwriter';
        $version = BinaryInstaller::PINNED_VERSION;
        file_put_contents($binPath, "#!/bin/sh\necho 'ghostwriter {$version}'\n");
        chmod($binPath, 0755);

        $io = new BufferIO();
        $event = $this->makeEvent($io, $this->tmpDir);

        BinaryInstaller::postInstall($event);

        self::assertStringContainsString("ghostwriter v{$version} already installed", $io->getOutput());
        self::assertFileExists($binPath);
    }

    private function makeEvent(BufferIO $io, string $binDir): Event
    {
        $config = new Config(false);
        $config->merge(['config' => ['bin-dir' => $binDir]]);

        $composer = new Composer();
        $composer->setConfig($config);

        return new Event('post-install-cmd', $composer, $io);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function buildFixtureTarGz(string $stagingDir, array $files): string
    {
        if (is_dir($stagingDir)) {
            $this->recursiveRemove($stagingDir);
        }
        mkdir($stagingDir, 0755, true);
        foreach ($files as $name => $contents) {
            file_put_contents($stagingDir.'/'.$name, $contents);
        }

        $tarPath = $this->tmpDir.'/pkg.tar';
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

        // Compress via gzencode rather than PharData::compress(GZ) — the
        // latter corrupts contents under some PHP 8.4 builds. Result is
        // identical to the upstream release tarballs the installer targets.
        $gzPath = $tarPath.'.gz';
        $tarBytes = file_get_contents($tarPath);
        if ($tarBytes === false) {
            throw new \RuntimeException('could not read fixture tar');
        }
        \Phar::unlinkArchive($tarPath);
        file_put_contents($gzPath, gzencode($tarBytes, 9));

        return $gzPath;
    }

    private function recursiveRemove(string $dir): void
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
            is_dir($path) ? $this->recursiveRemove($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
