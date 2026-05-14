<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console\Proxy;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ProxyRestartCommand extends ProxyCommand
{
    protected $signature = 'gaze:proxy:restart
        {--force : Force-kill (SIGKILL) if graceful stop times out}
        {--timeout= : Override gaze.proxy.stop_timeout (e.g. 30s)}';

    protected $description = 'Restart the gaze-proxy daemon (stop + start).';

    protected function verb(): string
    {
        return 'restart';
    }

    protected function flags(ConfigRepository $config): array
    {
        $argv = [];

        if ((bool) $this->option('force')) {
            $argv[] = '--force';
        }

        $this->appendFlag(
            $argv,
            'timeout',
            $this->stringOption('timeout') ?? $this->configString($config, 'gaze.proxy.stop_timeout'),
        );

        return $argv;
    }
}
