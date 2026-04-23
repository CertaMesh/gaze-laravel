<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

use Composer\Script\Event;

final class BinaryInstaller
{
    /** Pinned per gaze-laravel release. Bumped intentionally. */
    public const PINNED_VERSION = '0.1.0';

    private const RELEASE_BASE = 'https://github.com/Naoray/gaze/releases/download';

    public static function postInstall(Event $event): void
    {
        if (getenv('GAZE_SKIP_BINARY_DOWNLOAD') === '1') {
            $event->getIO()->write('<comment>gaze-laravel: skipping binary download (GAZE_SKIP_BINARY_DOWNLOAD=1)</comment>');

            return;
        }

        $version = getenv('GAZE_BINARY_VERSION');
        if (! is_string($version) || $version === '') {
            $version = self::PINNED_VERSION;
        }

        $binDir = (string) $event->getComposer()->getConfig()->get('bin-dir');

        $releaseBase = getenv('GAZE_RELEASE_BASE');
        if (! is_string($releaseBase) || $releaseBase === '') {
            $releaseBase = self::RELEASE_BASE;
        }
        if (! str_starts_with($releaseBase, 'https://')) {
            $event->getIO()->writeError('<error>gaze-laravel: refusing non-HTTPS release base</error>');

            return;
        }

        $target = self::detectTarget();
        if ($target === null) {
            $event->getIO()->writeError('<error>gaze-laravel: unsupported platform, please install ghostwriter manually and set GAZE_BINARY</error>');

            return; // do not fail composer install
        }

        $binPath = $binDir.DIRECTORY_SEPARATOR.'ghostwriter';
        if (self::alreadyInstalled($binPath, $version)) {
            $event->getIO()->write("<info>gaze-laravel: ghostwriter v{$version} already installed</info>");

            return;
        }

        $tag = "ghostwriter-v{$version}";
        $asset = "ghostwriter-v{$version}-{$target}.tar.gz";
        $assetUrl = "{$releaseBase}/{$tag}/{$asset}";
        $sumsUrl = "{$releaseBase}/{$tag}/SHA256SUMS";

        $tmpDir = sys_get_temp_dir();
        $tarPath = $tmpDir.DIRECTORY_SEPARATOR.$asset;
        $sumsPath = $tmpDir.DIRECTORY_SEPARATOR."SHA256SUMS-{$version}";

        try {
            self::download($assetUrl, $tarPath);
            self::download($sumsUrl, $sumsPath);
            self::verifyChecksum($tarPath, $sumsPath, $asset);
            self::extract($tarPath, $binDir);
            @chmod($binPath, 0755);
            $event->getIO()->write("<info>gaze-laravel: installed ghostwriter v{$version} → {$binPath}</info>");
        } catch (\Throwable $e) {
            $event->getIO()->writeError("<error>gaze-laravel: binary install failed — {$e->getMessage()}</error>");
            @unlink($binPath); // never leave partial artifact
            // Do NOT rethrow — composer install should succeed even if binary download fails.
            // Operator fixes GAZE_BINARY or runs composer install again.
        } finally {
            @unlink($tarPath);
            @unlink($sumsPath);
        }
    }

    public static function detectTarget(): ?string
    {
        $os = strtolower(PHP_OS_FAMILY);
        $arch = strtolower(php_uname('m'));

        return match (true) {
            $os === 'darwin' && in_array($arch, ['arm64', 'aarch64'], true) => 'aarch64-apple-darwin',
            $os === 'darwin' && $arch === 'x86_64' => 'x86_64-apple-darwin',
            $os === 'linux' && $arch === 'x86_64' => 'x86_64-unknown-linux-gnu',
            $os === 'linux' && in_array($arch, ['arm64', 'aarch64'], true) => 'aarch64-unknown-linux-gnu',
            default => null,
        };
    }

    public static function alreadyInstalled(string $binPath, string $version): bool
    {
        if (! is_executable($binPath)) {
            return false;
        }
        $output = @shell_exec(escapeshellarg($binPath).' --version 2>/dev/null');

        return is_string($output) && str_contains($output, $version);
    }

    public static function verifyChecksum(string $tarPath, string $sumsPath, string $asset): void
    {
        $sums = @file_get_contents($sumsPath);
        if ($sums === false) {
            throw new \RuntimeException('SHA256SUMS unreadable');
        }

        $expected = null;
        foreach (preg_split('/\r\n|\n/', $sums) ?: [] as $line) {
            if (preg_match('/^([a-f0-9]{64})\s+\*?'.preg_quote($asset, '/').'$/i', trim($line), $m)) {
                $expected = strtolower($m[1]);
                break;
            }
        }
        if ($expected === null) {
            throw new \RuntimeException("no checksum entry for {$asset}");
        }

        $actual = hash_file('sha256', $tarPath);
        if ($actual === false) {
            throw new \RuntimeException("could not hash {$tarPath}");
        }
        if (! hash_equals($expected, $actual)) {
            throw new \RuntimeException("sha256 mismatch for {$asset}");
        }
    }

    public static function extract(string $tarPath, string $binDir): void
    {
        $phar = new \PharData($tarPath);
        $gzPath = substr($tarPath, 0, -3); // .tar
        $phar->decompress();
        $tar = new \PharData($gzPath);
        $tar->extractTo($binDir, null, true);
        @unlink($gzPath);
    }

    private static function download(string $url, string $destPath): void
    {
        if (! str_starts_with($url, 'https://')) {
            throw new \RuntimeException('refusing non-HTTPS download');
        }

        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'follow_location' => 1, 'timeout' => 60],
            'https' => ['method' => 'GET', 'follow_location' => 1, 'timeout' => 60],
        ]);

        $bytes = @file_get_contents($url, false, $ctx);
        if ($bytes === false) {
            throw new \RuntimeException("download failed: {$url}");
        }
        if (file_put_contents($destPath, $bytes) === false) {
            throw new \RuntimeException("could not write {$destPath}");
        }
    }
}
