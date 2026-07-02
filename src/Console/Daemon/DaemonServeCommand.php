<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Daemon;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Exceptions\GazeBinaryMissingException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\Factory as ProcessFactory;

/**
 * Foreground `gaze daemon` wrapper, suitable for systemd / supervisord /
 * Horizon process-unit semantics. SIGTERM / SIGINT are forwarded to the
 * child via pcntl signal handlers so the supervisor's stop signal reaches
 * the binary's graceful-shutdown loop (spec L29).
 */
final class DaemonServeCommand extends DaemonCommand
{
    protected $signature = 'gaze:daemon:serve
        {--policy= : Override gaze.daemon.policy_path}
        {--idle-timeout= : Override gaze.daemon.idle_timeout_s (integer seconds)}
        {--session-idle-timeout= : Override gaze.daemon.session_idle_timeout_s (integer seconds)}
        {--session-cap= : Override gaze.daemon.session_cap (max live sessions before LRU eviction)}
        {--audit-db= : Override gaze.daemon.audit_db_path}
        {--locale= : Override gaze.locale (comma-separated, priority-ordered locale chain)}
        {--ner-threshold= : Override gaze.ner_threshold (0.0-1.0 inclusive)}';

    protected $description = 'Run the gaze-daemon JSONL worker in the foreground (blocks). Use under systemd/Horizon/supervisord.';

    public function handle(BinaryResolver $resolver, ConfigRepository $config, ProcessFactory $process): int
    {
        try {
            $argv = $this->buildArgv($resolver, $config);
        } catch (GazeBinaryMissingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $invoked = $process->newPendingProcess()->forever()->start($argv, function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        $this->installSignalForwarders($invoked);

        while ($invoked->running()) {
            if (function_exists('pcntl_signal_dispatch')) {
                @pcntl_signal_dispatch();
            }
            usleep(50_000);
        }

        $result = $invoked->wait();

        return $result->exitCode() ?? self::FAILURE;
    }

    protected function flags(ConfigRepository $config): array
    {
        $argv = [];

        $this->appendFlag(
            $argv,
            'policy',
            $this->stringOption('policy') ?? $this->configString($config, 'gaze.daemon.policy_path'),
        );

        // Safety-net enable + backend selector — sourced from the same
        // top-level `gaze.*` keys the one-shot Gaze::clean() path forwards,
        // so a configured pipeline behaves identically in both runtimes.
        // Mirrors clean(): a truthy gaze.safety_net emits the legacy
        // `--safety-net=openai-filter`; `--safety-net-backend` wins upstream
        // when both are present.
        if ((bool) $config->get('gaze.safety_net', false)) {
            $argv[] = '--safety-net=openai-filter';
        }

        $this->appendFlag($argv, 'safety-net-backend', $this->configString($config, 'gaze.safety_net_backend'));

        $this->appendFlag(
            $argv,
            'idle-timeout',
            $this->stringOption('idle-timeout') ?? $this->configNumericString($config, 'gaze.daemon.idle_timeout_s'),
        );

        $this->appendFlag(
            $argv,
            'session-idle-timeout',
            $this->stringOption('session-idle-timeout') ?? $this->configNumericString($config, 'gaze.daemon.session_idle_timeout_s'),
        );

        $this->appendFlag(
            $argv,
            'session-cap',
            $this->stringOption('session-cap') ?? $this->configNumericString($config, 'gaze.daemon.session_cap'),
        );

        $this->appendFlag(
            $argv,
            'audit-db',
            $this->stringOption('audit-db') ?? $this->configString($config, 'gaze.daemon.audit_db_path'),
        );

        $this->appendFlag(
            $argv,
            'locale',
            $this->stringOption('locale') ?? $this->configString($config, 'gaze.locale'),
        );

        $this->appendFlag(
            $argv,
            'ner-threshold',
            $this->stringOption('ner-threshold') ?? $this->configNumericString($config, 'gaze.ner_threshold'),
        );

        // Policy [ner] artifact overrides — config-only (no artisan option),
        // matching the one-shot posture that artifact paths are deployment
        // config, not per-invocation operational knobs.
        $this->appendFlag($argv, 'ner-model-dir', $this->configString($config, 'gaze.daemon.ner_model_dir'));
        $this->appendFlag($argv, 'ner-locale', $this->configString($config, 'gaze.daemon.ner_locale'));

        // OpenAI Privacy Filter (Tier 2) backend knobs — top-level `gaze.*`
        // keys shared with the one-shot path. Config-only.
        $this->appendFlag($argv, 'openai-filter-device', $this->configString($config, 'gaze.safety_net_device'));
        $this->appendFlag($argv, 'openai-filter-command', $this->configString($config, 'gaze.openai_filter_command'));
        $this->appendFlag($argv, 'openai-filter-checkpoint', $this->configString($config, 'gaze.openai_filter_checkpoint'));
        $this->appendFlag($argv, 'openai-filter-operating-point', $this->configString($config, 'gaze.openai_filter_operating_point'));

        // Kiji DistilBERT (Tier 2.5) backend knobs. `--kiji-distilbert-locales`
        // has no top-level one-shot key, so it lives under gaze.daemon.*.
        $this->appendFlag($argv, 'kiji-backend', $this->configString($config, 'gaze.kiji_backend'));
        $this->appendFlag($argv, 'kiji-distilbert-command', $this->configString($config, 'gaze.kiji_distilbert_command'));
        $this->appendFlag($argv, 'kiji-distilbert-model-dir', $this->configString($config, 'gaze.kiji_distilbert_model_dir'));
        $this->appendFlag($argv, 'kiji-distilbert-locales', $this->configString($config, 'gaze.daemon.kiji_distilbert_locales'));

        // Safety-net envelope limits + leak handling — top-level `gaze.*` keys
        // shared with the one-shot path. Config-only.
        $this->appendFlag($argv, 'safety-net-timeout-ms', $this->configNumericString($config, 'gaze.safety_net_timeout_ms'));
        $this->appendFlag($argv, 'safety-net-input-limit-bytes', $this->configNumericString($config, 'gaze.safety_net_input_limit_bytes'));
        $this->appendFlag($argv, 'safety-net-mode', $this->configString($config, 'gaze.safety_net_mode'));
        $this->appendFlag($argv, 'safety-net-fallback', $this->configString($config, 'gaze.safety_net_fallback'));

        return $argv;
    }

    /**
     * Register POSIX signal forwarders so SIGTERM/SIGINT from the supervisor
     * reach the child via Symfony's Process::signal(). No-op on platforms
     * lacking pcntl (Windows, --disable-pcntl builds).
     */
    private function installSignalForwarders(InvokedProcess $invoked): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $forward = static function (int $signal) use ($invoked): void {
            if ($invoked->running()) {
                $invoked->signal($signal);
            }
        };

        @pcntl_signal(SIGTERM, $forward);
        @pcntl_signal(SIGINT, $forward);
        @pcntl_async_signals(true);
    }
}
