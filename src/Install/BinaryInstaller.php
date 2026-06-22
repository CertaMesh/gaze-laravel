<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Symfony\Component\Process\Process;

final class BinaryInstaller
{
    /** Pinned per gaze-laravel release. Bumped intentionally. */
    public const PINNED_VERSION = '0.11.1';

    private const RELEASE_BASE = 'https://github.com/CertaMesh/gaze/releases/download';

    /**
     * Composer script handler kept as a thin shim so root composer.json
     * `post-install-cmd` / `post-update-cmd` entries that already reference
     * this static keep working. New consumers get triggered via the
     * GazeInstallerPlugin (extra.class) without any root config.
     */
    public static function postInstall(Event $event): void
    {
        self::install($event->getComposer(), $event->getIO());
    }

    public static function install(Composer $composer, IOInterface $io): void
    {
        if (getenv('GAZE_SKIP_BINARY_DOWNLOAD') === '1') {
            $io->write('<comment>gaze-laravel: skipping binary download (GAZE_SKIP_BINARY_DOWNLOAD=1)</comment>');

            return;
        }

        $version = getenv('GAZE_VERSION');
        if (! is_string($version) || $version === '') {
            $version = self::PINNED_VERSION;
        }

        $binDir = (string) $composer->getConfig()->get('bin-dir');

        $releaseBase = self::resolveReleaseBase($io);
        if (! str_starts_with($releaseBase, 'https://')) {
            $io->writeError('<error>gaze-laravel: refusing non-HTTPS release base</error>');

            return;
        }

        $target = self::detectTarget();
        if ($target === null) {
            $io->writeError('<error>gaze-laravel: unsupported platform, please install gaze manually and set GAZE_BINARY</error>');

            return; // do not fail composer install
        }

        if ($target === 'x86_64-apple-darwin') {
            // TODO(gaze-v0.7): mention `cargo install gaze-cli` once published to crates.io.
            $io->writeError('<error>gaze-laravel: pre-built macOS binaries are arm64-only on Intel; clone https://github.com/CertaMesh/gaze and run `cargo install --path crates/gaze-cli`, then set GAZE_BINARY.</error>');

            return;
        }

        $binPath = $binDir.DIRECTORY_SEPARATOR.'gaze';
        if (self::alreadyInstalled($binPath, $version)) {
            $io->write("<info>gaze-laravel: gaze v{$version} already installed</info>");

            return;
        }

        $tag = "v{$version}";
        $asset = "gaze-{$target}";
        $assetUrl = "{$releaseBase}/{$tag}/{$asset}";
        $sumsUrl = "{$releaseBase}/{$tag}/{$asset}.sha256";

        $tmpDir = sys_get_temp_dir();
        $assetPath = $tmpDir.DIRECTORY_SEPARATOR.$asset;
        $sumsPath = $tmpDir.DIRECTORY_SEPARATOR."{$asset}.sha256";

        $tokenEnv = getenv('GAZE_GITHUB_TOKEN');
        $token = (is_string($tokenEnv) && $tokenEnv !== '') ? $tokenEnv : null;
        $githubRepo = self::deriveGithubRepo($releaseBase);

        try {
            if ($token !== null && $githubRepo !== null) {
                [$assetId, $sumsAssetId] = self::resolveReleaseAssetIds($githubRepo, $tag, $asset, $token);
                self::downloadGithubAsset($githubRepo, $assetId, $assetPath, $token);
                self::downloadGithubAsset($githubRepo, $sumsAssetId, $sumsPath, $token);
            } else {
                if ($token !== null && $githubRepo === null) {
                    $io->writeError('<comment>gaze-laravel: GAZE_GITHUB_TOKEN set but GAZE_RELEASE_BASE is not a github.com release URL — token ignored</comment>');
                }
                self::download($assetUrl, $assetPath);
                self::download($sumsUrl, $sumsPath);
            }
            self::verifyChecksum($assetPath, $sumsPath, $asset);
            self::installBinary($assetPath, $binPath);
            @chmod($binPath, 0755);
            $io->write("<info>gaze-laravel: installed gaze v{$version} → {$binPath}</info>");
            $io->write('<comment>gaze-laravel: gaze proxy is opt-in. To use `php artisan gaze:proxy:*`, rebuild upstream with: cargo install gaze-cli --features proxy</comment>');
        } catch (\Throwable $e) {
            // Scrub the token if it ever ended up in an exception message.
            $message = $token !== null ? str_replace($token, '[redacted]', $e->getMessage()) : $e->getMessage();
            $io->writeError("<error>gaze-laravel: binary install failed — {$message}</error>");
            @unlink($binPath); // never leave partial artifact
            // Do NOT rethrow — composer install should succeed even if binary download fails.
            // Operator fixes GAZE_BINARY or runs composer install again.
        } finally {
            @unlink($assetPath);
            @unlink($sumsPath);
        }
    }

    /** @internal exposed for tests */
    public static function resolveReleaseBase(IOInterface $io): string
    {
        $releaseBase = getenv('GAZE_RELEASE_BASE');
        if (! is_string($releaseBase) || $releaseBase === '') {
            return self::RELEASE_BASE;
        }

        if (self::isProductionEnvironment()) {
            return self::RELEASE_BASE;
        }

        $io->writeError('<comment>gaze-laravel: using non-canonical GAZE_RELEASE_BASE override outside production</comment>');

        return $releaseBase;
    }

    /** @internal exposed for tests */
    public static function isProductionEnvironment(): bool
    {
        $appEnv = getenv('APP_ENV');
        if (! is_string($appEnv) || trim($appEnv) === '') {
            return true;
        }

        return in_array(strtolower(trim($appEnv)), ['production', 'prod'], true);
    }

    public static function detectTarget(): ?string
    {
        $os = strtolower(PHP_OS_FAMILY);
        $arch = strtolower(php_uname('m'));

        return match (true) {
            $os === 'darwin' && in_array($arch, ['arm64', 'aarch64'], true) => 'aarch64-apple-darwin',
            $os === 'darwin' && $arch === 'x86_64' => 'x86_64-apple-darwin',
            $os === 'linux' && $arch === 'x86_64' => 'x86_64-linux-gnu',
            $os === 'linux' && in_array($arch, ['arm64', 'aarch64'], true) => 'aarch64-unknown-linux-gnu',
            default => null,
        };
    }

    public static function alreadyInstalled(string $binPath, string $version): bool
    {
        if (! is_executable($binPath)) {
            return false;
        }

        $process = new Process([$binPath, '--version']);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        if (! $process->isSuccessful()) {
            return false;
        }

        $output = $process->getOutput();

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

    public static function installBinary(string $assetPath, string $binPath): void
    {
        if (str_ends_with($assetPath, '.tar.gz')) {
            self::extract($assetPath, dirname($binPath));

            return;
        }

        if (@copy($assetPath, $binPath) === false) {
            throw new \RuntimeException("could not write {$binPath}");
        }
    }

    private const MAX_REDIRECTS = 5;

    /**
     * Download the URL to destPath. Redirects are followed manually so every
     * hop is re-checked for `https://`; PHP's native `follow_location` does
     * not enforce this and would silently downgrade to plain HTTP. The
     * Authorization header is dropped on cross-host redirects (e.g. when
     * api.github.com hands off to an S3 signed URL — adding our Bearer to
     * that pre-signed URL would either leak the token or 400 the request).
     *
     * @param  list<string>  $headers
     */
    private static function download(string $url, string $destPath, array $headers = []): void
    {
        $bytes = self::fetch($url, $headers);
        if (file_put_contents($destPath, $bytes) === false) {
            throw new \RuntimeException("could not write {$destPath}");
        }
    }

    /**
     * @param  list<string>  $headers
     */
    private static function fetch(string $url, array $headers = []): string
    {
        $originalHost = self::hostOf($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (! str_starts_with($url, 'https://')) {
                throw new \RuntimeException('refusing non-HTTPS url');
            }

            $currentHost = self::hostOf($url);
            $effectiveHeaders = self::stripAuthOnCrossHost($headers, $originalHost, $currentHost);

            $opts = [
                'method' => 'GET',
                'follow_location' => 0,
                'ignore_errors' => true,
                'timeout' => 60,
            ];
            if ($effectiveHeaders !== []) {
                $opts['header'] = implode("\r\n", $effectiveHeaders);
            }

            $ctx = stream_context_create([
                'http' => $opts,
                'https' => $opts,
            ]);

            $bytes = @file_get_contents($url, false, $ctx);
            if ($bytes === false) {
                throw new \RuntimeException("download failed: {$url}");
            }

            /** @var list<string> $responseHeaders */
            $responseHeaders = $http_response_header;

            [$status, $location] = self::parseStatusAndLocation($responseHeaders);

            if ($status >= 200 && $status < 300) {
                return $bytes;
            }

            if ($status >= 300 && $status < 400 && $location !== null) {
                $url = self::resolveLocation($url, $location);

                continue;
            }

            throw new \RuntimeException("download failed with HTTP {$status}");
        }

        throw new \RuntimeException('too many redirects');
    }

    /**
     * Derive `<owner>/<repo>` from a GitHub release-download base URL, or
     * return null when the base is not a github.com release URL.
     *
     * @internal exposed for tests
     */
    public static function deriveGithubRepo(string $releaseBase): ?string
    {
        if (preg_match('~^https://github\.com/([^/]+)/([^/]+)/releases/download~', $releaseBase, $m)) {
            return $m[1].'/'.$m[2];
        }

        return null;
    }

    /**
     * Build the request headers for a GitHub API or asset call. The token is
     * never logged; the Authorization line is only present when a token is
     * supplied.
     *
     * @return list<string>
     *
     * @internal exposed for tests
     */
    public static function buildRequestHeaders(?string $token, string $accept): array
    {
        $headers = [
            'User-Agent: gaze-laravel/'.self::PINNED_VERSION,
            'Accept: '.$accept,
        ];
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer '.$token;
            $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
        }

        return $headers;
    }

    /**
     * Resolve the asset id pair (binary + .sha256) for a given release tag,
     * via a single `releases/tags/{tag}` API call.
     *
     * @return array{0: int, 1: int}
     */
    private static function resolveReleaseAssetIds(string $repo, string $tag, string $asset, string $token): array
    {
        $url = "https://api.github.com/repos/{$repo}/releases/tags/{$tag}";
        $body = self::fetch($url, self::buildRequestHeaders($token, 'application/vnd.github+json'));

        return self::extractAssetIds($body, $asset, $tag);
    }

    /**
     * @return array{0: int, 1: int}
     *
     * @internal exposed for tests
     */
    public static function extractAssetIds(string $jsonBody, string $asset, string $tag): array
    {
        $payload = json_decode($jsonBody, true);
        if (! is_array($payload) || ! isset($payload['assets']) || ! is_array($payload['assets'])) {
            throw new \RuntimeException("github release {$tag}: invalid JSON or no assets[]");
        }

        $byName = [];
        foreach ($payload['assets'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? null;
            $id = $entry['id'] ?? null;
            if (is_string($name) && is_int($id)) {
                $byName[$name] = $id;
            }
        }

        $sumsName = $asset.'.sha256';
        if (! isset($byName[$asset])) {
            throw new \RuntimeException("github release {$tag}: asset {$asset} not found");
        }
        if (! isset($byName[$sumsName])) {
            throw new \RuntimeException("github release {$tag}: asset {$sumsName} not found");
        }

        return [$byName[$asset], $byName[$sumsName]];
    }

    private static function downloadGithubAsset(string $repo, int $assetId, string $destPath, string $token): void
    {
        $url = "https://api.github.com/repos/{$repo}/releases/assets/{$assetId}";
        self::download($url, $destPath, self::buildRequestHeaders($token, 'application/octet-stream'));
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    /**
     * Drop the Authorization header when following a redirect to a different
     * host. This is the same rule curl applies with `--location` (without
     * `--location-trusted`) and what `gh` follows when fetching private
     * release assets — the redirect target is a presigned S3 URL and our
     * Bearer token has no business going there.
     *
     * @param  list<string>  $headers
     * @return list<string>
     *
     * @internal exposed for tests
     */
    public static function stripAuthOnCrossHost(array $headers, ?string $originalHost, ?string $currentHost): array
    {
        if ($originalHost !== null && $currentHost !== null && $originalHost === $currentHost) {
            return $headers;
        }

        return array_values(array_filter(
            $headers,
            static fn (string $h): bool => stripos($h, 'Authorization:') !== 0,
        ));
    }

    /**
     * @param  list<string>  $headers
     * @return array{0: int, 1: ?string}
     *
     * @internal exposed for tests
     */
    public static function parseStatusAndLocation(array $headers): array
    {
        $status = 0;
        $location = null;
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+ (\d{3})~', $header, $m)) {
                $status = (int) $m[1];
                $location = null; // reset across multiple status lines
            }
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }

        return [$status, $location];
    }

    /** @internal exposed for tests */
    public static function resolveLocation(string $current, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($current);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('could not parse redirect origin URL');
        }
        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $base.$location;
        }

        // Relative path — strip file from current, join.
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);

        return $base.$dir.$location;
    }
}
