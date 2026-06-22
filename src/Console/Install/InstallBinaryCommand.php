<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Install;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Install\BinaryDownloader;
use CertaMesh\Gaze\Install\BinaryDownloadOptions;
use CertaMesh\Gaze\Install\BinaryDownloadStatus;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Process\Factory as ProcessFactory;

/**
 * Install the pinned gaze binary from artisan (the same pipeline the Composer
 * plugin uses), independent of `composer install`.
 *
 * Unlike the best-effort Composer path, an explicit artisan invocation surfaces
 * a real exit code — but only when it genuinely cannot provision a usable
 * binary. When a runnable binary already resolves (`GAZE_BINARY`, vendor/bin,
 * or PATH), the command defers to it and succeeds (CB5): the binary-pin is an
 * optimisation, never a hard gate for adopters who build from source on a
 * platform with no pre-built asset.
 */
final class InstallBinaryCommand extends Command
{
    protected $signature = 'gaze:install:binary
        {--force : Re-download even if the pinned version is already installed}';

    protected $description = 'Install the pinned gaze binary into vendor/bin (where BinaryResolver looks).';

    public function handle(BinaryDownloader $downloader, BinaryResolver $resolver, ProcessFactory $process, Application $app): int
    {
        $force = (bool) $this->option('force');

        // CB5: a runnable binary already resolves — doctor is satisfied. Skip
        // the download unless the adopter explicitly forces a re-download.
        if (! $force && ($resolved = $this->resolveRunnable($resolver, $process)) !== null) {
            $this->components->info(
                "gaze already resolves to {$resolved}; nothing to install "
                .'(use --force to re-download the pinned v'.BinaryDownloader::PINNED_VERSION.').'
            );

            return self::SUCCESS;
        }

        $binDir = dirname($app->basePath('vendor/bin/gaze'));

        $result = $downloader->install(
            new BinaryDownloadOptions(binDir: $binDir, force: $force),
            function (string $level, string $message): void {
                match ($level) {
                    'error' => $this->components->error($message),
                    'warning' => $this->components->warn($message),
                    default => $this->components->info($message),
                };
            },
        );

        return match ($result->status) {
            BinaryDownloadStatus::Installed,
            BinaryDownloadStatus::AlreadySatisfied,
            BinaryDownloadStatus::Skipped => self::SUCCESS,
            BinaryDownloadStatus::UnsupportedPlatform => $this->handleUnsupportedPlatform($resolver, $process),
            BinaryDownloadStatus::Failed => self::FAILURE,
        };
    }

    /**
     * CB5: unsupported platform is a hard failure ONLY when no binary resolves
     * anywhere. When the adopter built from source / set GAZE_BINARY, this is a
     * skip-with-guidance success.
     */
    private function handleUnsupportedPlatform(BinaryResolver $resolver, ProcessFactory $process): int
    {
        if ($this->resolveRunnable($resolver, $process) !== null) {
            $this->components->warn(
                'No pre-built gaze binary for this platform; using the already-resolvable binary. '
                .'To pin a build, build from source (cargo install --path crates/gaze-cli) and set GAZE_BINARY.'
            );

            return self::SUCCESS;
        }

        $this->components->error(
            'No pre-built gaze binary for this platform and none resolvable. '
            .'Build from source (cargo install --path crates/gaze-cli) and set GAZE_BINARY, or put gaze on PATH.'
        );

        return self::FAILURE;
    }

    /** Mirror doctor's trust boundary: resolve a path, then confirm `--version` runs. */
    private function resolveRunnable(BinaryResolver $resolver, ProcessFactory $process): ?string
    {
        $binary = $resolver->resolveOrNull();
        if ($binary === null) {
            return null;
        }

        return $process->newPendingProcess()->timeout(5)->run([$binary, '--version'])->successful()
            ? $binary
            : null;
    }
}
