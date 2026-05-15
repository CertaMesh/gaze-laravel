<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Encryption\Encrypter;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\ServiceProvider;
use Naoray\GazeLaravel\Audit\AuditService;
use Naoray\GazeLaravel\Console\BenchCommand;
use Naoray\GazeLaravel\Console\CanaryCommand;
use Naoray\GazeLaravel\Console\CheckCommand;
use Naoray\GazeLaravel\Console\DoctorCommand;
use Naoray\GazeLaravel\Console\InstallNerCommand;
use Naoray\GazeLaravel\Console\Proxy\ProxyLogsCommand;
use Naoray\GazeLaravel\Console\Proxy\ProxyRestartCommand;
use Naoray\GazeLaravel\Console\Proxy\ProxyServeCommand;
use Naoray\GazeLaravel\Console\Proxy\ProxyStartCommand;
use Naoray\GazeLaravel\Console\Proxy\ProxyStatusCommand;
use Naoray\GazeLaravel\Console\Proxy\ProxyStopCommand;
use Naoray\GazeLaravel\Install\BinaryInstaller;
use Naoray\GazeLaravel\Install\LaravelNerFetcher;
use Naoray\GazeLaravel\Install\NerFetcher;
use Naoray\GazeLaravel\Install\NerInstaller;
use Naoray\GazeLaravel\Install\NerManifest;
use Naoray\GazeLaravel\Install\PolicyTomlPatcher;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GazeServiceProvider extends ServiceProvider implements DeferrableProvider
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
                sessionScope: is_string($config->get('gaze.session_scope')) && $config->get('gaze.session_scope') !== '' ? $config->get('gaze.session_scope') : null,
                locale: is_string($config->get('gaze.locale')) && $config->get('gaze.locale') !== '' ? $config->get('gaze.locale') : null,
                rulepacks: is_array($config->get('gaze.rulepacks')) && count($config->get('gaze.rulepacks')) > 0 ? $config->get('gaze.rulepacks') : null,
                rulepackPaths: is_array($config->get('gaze.rulepack_paths')) && count($config->get('gaze.rulepack_paths')) > 0 ? $config->get('gaze.rulepack_paths') : null,
                safetyNet: (bool) $config->get('gaze.safety_net', false),
                safetyNetDevice: is_string($config->get('gaze.safety_net_device')) && $config->get('gaze.safety_net_device') !== '' ? $config->get('gaze.safety_net_device') : null,
                openaiFilterCommand: is_string($config->get('gaze.openai_filter_command')) && $config->get('gaze.openai_filter_command') !== '' ? $config->get('gaze.openai_filter_command') : null,
                openaiFilterCheckpoint: is_string($config->get('gaze.openai_filter_checkpoint')) && $config->get('gaze.openai_filter_checkpoint') !== '' ? $config->get('gaze.openai_filter_checkpoint') : null,
                openaiFilterOperatingPoint: is_string($config->get('gaze.openai_filter_operating_point')) && $config->get('gaze.openai_filter_operating_point') !== '' ? $config->get('gaze.openai_filter_operating_point') : null,
                safetyNetTimeoutMs: is_numeric($config->get('gaze.safety_net_timeout_ms')) ? (int) $config->get('gaze.safety_net_timeout_ms') : null,
                safetyNetInputLimitBytes: is_numeric($config->get('gaze.safety_net_input_limit_bytes')) ? (int) $config->get('gaze.safety_net_input_limit_bytes') : null,
                safetyNetMode: is_string($config->get('gaze.safety_net_mode')) && $config->get('gaze.safety_net_mode') !== '' ? $config->get('gaze.safety_net_mode') : null,
                safetyNetBackend: is_string($config->get('gaze.safety_net_backend')) && $config->get('gaze.safety_net_backend') !== '' ? $config->get('gaze.safety_net_backend') : null,
                kijiDistilbertCommand: is_string($config->get('gaze.kiji_distilbert_command')) && $config->get('gaze.kiji_distilbert_command') !== '' ? $config->get('gaze.kiji_distilbert_command') : null,
                kijiDistilbertModelDir: is_string($config->get('gaze.kiji_distilbert_model_dir')) && $config->get('gaze.kiji_distilbert_model_dir') !== '' ? $config->get('gaze.kiji_distilbert_model_dir') : null,
                safetyNetFallback: is_string($config->get('gaze.safety_net_fallback')) && $config->get('gaze.safety_net_fallback') !== '' ? $config->get('gaze.safety_net_fallback') : null,
                restoreMode: is_string($config->get('gaze.restore_mode')) && $config->get('gaze.restore_mode') !== '' ? $config->get('gaze.restore_mode') : null,
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

        $this->app->singleton(HttpClientInterface::class, function (): HttpClientInterface {
            return new RetryableHttpClient(HttpClient::create(), maxRetries: 2);
        });

        $this->app->singleton(LaravelNerFetcher::class, function (Application $app): LaravelNerFetcher {
            return new LaravelNerFetcher(
                client: $app->make(HttpClientInterface::class),
                resourcesDir: __DIR__.'/../resources/ner',
            );
        });

        $this->app->singleton(NerFetcher::class, LaravelNerFetcher::class);

        $this->app->singleton(NerManifest::class, function (Application $app): NerManifest {
            $version = BinaryInstaller::PINNED_VERSION;
            $url = "https://github.com/EmpireTwo/gaze/releases/download/v{$version}/SHA256SUMS.ner";

            return NerManifest::fromUrl($url, $app->make(HttpClientInterface::class));
        });

        $this->app->singleton(PolicyTomlPatcher::class, function (Application $app): PolicyTomlPatcher {
            return new PolicyTomlPatcher(baseDir: $app->basePath());
        });

        $this->app->singleton(NerInstaller::class, function (Application $app): NerInstaller {
            return new NerInstaller(
                fetcher: $app->make(NerFetcher::class),
                patcher: $app->make(PolicyTomlPatcher::class),
                manifest: $app->make(NerManifest::class),
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
                __DIR__.'/../resources/policy.toml' => $this->app->basePath('policy.toml'),
            ], 'gaze-policy');

            $this->commands([
                CheckCommand::class,
                DoctorCommand::class,
                CanaryCommand::class,
                BenchCommand::class,
                InstallNerCommand::class,
                ProxyServeCommand::class,
                ProxyStartCommand::class,
                ProxyStopCommand::class,
                ProxyRestartCommand::class,
                ProxyStatusCommand::class,
                ProxyLogsCommand::class,
            ]);
        }
    }

    /**
     * @return list<class-string|string>
     */
    public function provides(): array
    {
        return [
            BinaryResolver::class,
            Gaze::class,
            AuditService::class,
            HttpClientInterface::class,
            LaravelNerFetcher::class,
            NerFetcher::class,
            NerManifest::class,
            NerInstaller::class,
            PolicyTomlPatcher::class,
            'gaze.encrypter',
        ];
    }
}
