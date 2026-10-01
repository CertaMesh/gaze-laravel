<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Proxy;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;

final class ProxyServeCommand extends ProxyCommand
{
    protected $signature = 'gaze:proxy:serve
        {--bind= : Override gaze.proxy.bind (e.g. 127.0.0.1:8787)}
        {--policy= : Override gaze.proxy.policy_path}
        {--rulepack= : Override gaze.proxy.rulepack (default: core)}
        {--session-ttl= : Override gaze.proxy.session_ttl (e.g. 30m)}
        {--foreground-daemon : Run with the systemd/launchd foreground-daemon contract (pidfile + stdout streamed)}';

    protected $description = 'Run the gaze-proxy daemon in the foreground (blocks). Use in dev / containers.';

    protected function verb(): string
    {
        return 'serve';
    }

    protected function flags(ConfigRepository $config): array
    {
        $argv = $this->launchFlags($config);

        // Upstream's wire spelling is `--_foreground-daemon` (a hidden clap
        // arg: the re-exec contract `gaze proxy start` uses when it detaches).
        // The underscore-less spelling exits 2 `PolicyConfig` on every pin
        // since 0.8.0, so the artisan option keeps its clean name and only the
        // forwarded flag carries the underscore.
        if ((bool) $this->option('foreground-daemon')) {
            $argv[] = '--_foreground-daemon';
        }

        return $argv;
    }

    protected function runProcess(array $argv, ConfigRepository $config, ProcessFactory $process): int
    {
        return $this->streamProcess($argv, $process);
    }
}
