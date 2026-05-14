<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console\Proxy;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;

final class ProxyLogsCommand extends ProxyCommand
{
    protected $signature = 'gaze:proxy:logs {--follow : Tail the log file in foreground (blocks until interrupted)}';

    protected $description = 'Show the gaze-proxy log file. Pass --follow to tail.';

    protected function verb(): string
    {
        return 'logs';
    }

    protected function flags(ConfigRepository $config): array
    {
        $argv = [];

        if ((bool) $this->option('follow')) {
            $argv[] = '--follow';
        }

        return $argv;
    }

    protected function runProcess(array $argv, ConfigRepository $config, ProcessFactory $process): int
    {
        if ((bool) $this->option('follow')) {
            return $this->streamProcess($argv, $process);
        }

        return parent::runProcess($argv, $config, $process);
    }
}
