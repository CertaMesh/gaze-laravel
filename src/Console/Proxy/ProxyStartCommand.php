<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console\Proxy;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ProxyStartCommand extends ProxyCommand
{
    protected $signature = 'gaze:proxy:start
        {--bind= : Override gaze.proxy.bind (e.g. 127.0.0.1:8787)}
        {--policy= : Override gaze.proxy.policy_path}
        {--rulepack= : Override gaze.proxy.rulepack (default: core)}
        {--session-ttl= : Override gaze.proxy.session_ttl (e.g. 30m)}';

    protected $description = 'Start the gaze-proxy daemon in the background.';

    protected function verb(): string
    {
        return 'start';
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

        return $argv;
    }
}
