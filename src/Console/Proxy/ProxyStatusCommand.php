<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console\Proxy;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;

final class ProxyStatusCommand extends ProxyCommand
{
    protected $signature = 'gaze:proxy:status';

    protected $description = 'Check whether the gaze-proxy daemon is running. Exits 0 when running, 1 when stopped.';

    protected function verb(): string
    {
        return 'status';
    }

    protected function flags(ConfigRepository $config): array
    {
        return [];
    }

    /**
     * Upstream `gaze proxy status` always exits 0 and prints `gaze-proxy
     * running (...)` or `gaze-proxy not running`. We translate the latter to
     * a non-zero exit so CI / Sentry can probe the daemon directly.
     */
    protected function runProcess(array $argv, ConfigRepository $config, ProcessFactory $process): int
    {
        $timeout = (int) $config->get('gaze.timeout_seconds', 30);

        $result = $process->newPendingProcess()->timeout($timeout)->run($argv);

        $stdout = rtrim($result->output());
        if ($stdout !== '') {
            $this->line($stdout);
        }

        if (! $result->successful()) {
            $stderr = rtrim($result->errorOutput());
            if ($stderr !== '') {
                $this->error($stderr);
            }

            return $result->exitCode() ?? self::FAILURE;
        }

        return str_contains($stdout, 'not running') ? self::FAILURE : self::SUCCESS;
    }
}
