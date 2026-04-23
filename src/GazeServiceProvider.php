<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\ServiceProvider;
use Naoray\GazeLaravel\Console\CanaryCommand;
use Naoray\GazeLaravel\Console\CheckCommand;

class GazeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gaze.php', 'gaze');

        $this->app->singleton(BinaryResolver::class, function (Application $app): BinaryResolver {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            $explicit = $config->get('gaze.binary');

            return new BinaryResolver(
                explicitPath: is_string($explicit) && $explicit !== '' ? $explicit : null,
                vendorBinPath: $app->basePath('vendor/bin/ghostwriter'),
            );
        });

        $this->app->singleton(Gaze::class, function (Application $app): Gaze {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new Gaze(
                resolver: $app->make(BinaryResolver::class),
                process: $app->make(ProcessFactory::class),
                timeoutSeconds: (int) $config->get('gaze.timeout_seconds', 30),
                failClosed: (bool) $config->get('gaze.fail_closed', true),
            );
        });

        $this->app->singleton('gaze.encrypter', function (Application $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            $raw = $config->get('gaze.blob_encryption_key');

            if ($raw === null || $raw === '') {
                return $app->make('encrypter');
            }

            if (! is_string($raw)) {
                throw new \RuntimeException('GAZE_ENCRYPTION_KEY must be a string.');
            }

            $decoded = base64_decode($raw, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new \RuntimeException(
                    'GAZE_ENCRYPTION_KEY must be base64-encoded 32 bytes.'
                );
            }

            return new Encrypter($decoded, 'AES-256-CBC');
        });

        $this->app->singleton(EncryptedBlob::class, function (Application $app): EncryptedBlob {
            /** @var \Illuminate\Contracts\Encryption\Encrypter&\Illuminate\Contracts\Encryption\StringEncrypter $encrypter */
            $encrypter = $app->make('gaze.encrypter');

            return new EncryptedBlob($encrypter);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/gaze.php' => $this->app->configPath('gaze.php'),
            ], 'gaze-config');

            $this->commands([
                CheckCommand::class,
                CanaryCommand::class,
            ]);
        }
    }
}
