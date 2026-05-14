<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console\Proxy;

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
        $argv = [];

        $this->appendFlag(
            $argv,
            'bind',
            $this->stringOption('bind') ?? $this->configString($config, 'gaze.proxy.bind'),
        );
        $this->appendFlag($argv, 'upstream-openai', $this->configString($config, 'gaze.proxy.upstream.openai'));
        $this->appendFlag($argv, 'upstream-anthropic', $this->configString($config, 'gaze.proxy.upstream.anthropic'));
        $this->appendFlag($argv, 'upstream-gemini', $this->configString($config, 'gaze.proxy.upstream.gemini'));
        $this->appendFlag(
            $argv,
            'policy',
            $this->stringOption('policy') ?? $this->configString($config, 'gaze.proxy.policy_path'),
        );
        $this->appendFlag(
            $argv,
            'rulepack',
            $this->stringOption('rulepack') ?? $this->configString($config, 'gaze.proxy.rulepack'),
        );
        $this->appendFlag(
            $argv,
            'session-ttl',
            $this->stringOption('session-ttl') ?? $this->configString($config, 'gaze.proxy.session_ttl'),
        );

        if ((bool) $this->option('foreground-daemon')) {
            $argv[] = '--foreground-daemon';
        }

        return $argv;
    }

    protected function runProcess(array $argv, ConfigRepository $config, ProcessFactory $process): int
    {
        return $this->streamProcess($argv, $process);
    }
}
