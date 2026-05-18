<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console\Daemon;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Contracts\Process\InvokedProcess;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;

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
        {--audit-db= : Override gaze.daemon.audit_db_path}';

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

        $this->appendFlag(
            $argv,
            'idle-timeout',
            $this->stringOption('idle-timeout') ?? $this->configNumericString($config, 'gaze.daemon.idle_timeout_s'),
        );

        $this->appendFlag(
            $argv,
            'audit-db',
            $this->stringOption('audit-db') ?? $this->configString($config, 'gaze.daemon.audit_db_path'),
        );

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
