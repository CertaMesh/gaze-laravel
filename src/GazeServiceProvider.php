<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\ServiceProvider;
use Naoray\GazeLaravel\Audit\AuditService;
use Naoray\GazeLaravel\Console\CanaryCommand;
use Naoray\GazeLaravel\Console\CheckCommand;
use Naoray\GazeLaravel\Console\DoctorCommand;

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
                vendorBinPath: $app->basePath('vendor/bin/gaze'),
            );
        });

        $this->app->singleton(Gaze::class, function (Application $app): Gaze {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            $rawAuditDbPath = $config->get('gaze.audit_db_path');

            return new Gaze(
                resolver: $app->make(BinaryResolver::class),
                process: $app->make(ProcessFactory::class),
                timeoutSeconds: (int) $config->get('gaze.timeout_seconds', 30),
                policyPath: (string) $config->get('gaze.policy_path', $app->basePath('policy.toml')),
                maxBytes: is_numeric($config->get('gaze.max_bytes')) ? (int) $config->get('gaze.max_bytes') : null,
                sessionTtlSeconds: is_numeric($config->get('gaze.session_ttl_seconds')) ? (int) $config->get('gaze.session_ttl_seconds') : null,
                auditDbPath: is_string($rawAuditDbPath) && $rawAuditDbPath !== '' ? $rawAuditDbPath : null,
                container: $app,
            );
        });

        $this->app->singleton(AuditService::class, function (Application $app): AuditService {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            $rawAuditDbPath = $config->get('gaze.audit_db_path');

            return new AuditService(
                gaze: $app->make(Gaze::class),
                resolver: $app->make(BinaryResolver::class),
                auditDbPath: is_string($rawAuditDbPath) && $rawAuditDbPath !== '' ? $rawAuditDbPath : null,
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

            if (str_starts_with($raw, 'base64:')) {
                $raw = substr($raw, 7);
            }

            $decoded = base64_decode($raw, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new \RuntimeException(
                    'GAZE_ENCRYPTION_KEY must be base64-encoded 32 bytes.'
                );
            }

            $cipher = $config->get('app.cipher');
            if (! is_string($cipher) || $cipher === '') {
                $cipher = 'AES-256-CBC';
            }

            return new Encrypter($decoded, $cipher);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/gaze.php' => $this->app->configPath('gaze.php'),
            ], 'gaze-config');
            $this->publishes([
                __DIR__.'/../policy.toml.example' => $this->app->basePath('policy.toml'),
            ], 'gaze-policy');

            $this->commands([
                CheckCommand::class,
                DoctorCommand::class,
                CanaryCommand::class,
            ]);
        }
    }
}
