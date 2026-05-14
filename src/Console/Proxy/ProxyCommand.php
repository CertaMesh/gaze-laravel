<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console\Proxy;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;

/**
 * Base class for the gaze:proxy:* artisan wrappers.
 *
 * Each concrete subcommand shells out to the upstream `gaze proxy <verb>`
 * subcommand via Symfony Process. Subclasses own (a) the `proxy` verb and
 * (b) the verb-specific flag list assembled from config + artisan options.
 *
 * Adopters need the upstream binary built with `cargo install gaze-cli
 * --features proxy` — the GitHub-release binary asset is built without the
 * feature.
 */
abstract class ProxyCommand extends Command
{
    /**
     * The proxy subcommand name (e.g. `start`, `stop`, `serve`).
     */
    abstract protected function verb(): string;

    /**
     * Verb-specific flag list. Items must be `--flag` or `--flag=value`
     * strings ready to forward to the binary.
     *
     * @return list<string>
     */
    abstract protected function flags(ConfigRepository $config): array;

    /**
     * Build the full argv: `[binary, proxy, verb, ...flags]`.
     *
     * @return list<string>
     */
    public function buildArgv(BinaryResolver $resolver, ConfigRepository $config): array
    {
        $argv = [$resolver->resolve(), 'proxy', $this->verb()];

        foreach ($this->flags($config) as $flag) {
            $argv[] = $flag;
        }

        return $argv;
    }

    public function handle(BinaryResolver $resolver, ConfigRepository $config, ProcessFactory $process): int
    {
        try {
            $argv = $this->buildArgv($resolver, $config);
        } catch (GazeBinaryMissingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->runProcess($argv, $config, $process);
    }

    /**
     * Default run strategy: bounded by `gaze.timeout_seconds`, pass through
     * stdout/stderr verbatim, return the binary's exit code.
     *
     * Subclasses that need streaming (`serve`, `logs --follow`) override.
     *
     * @param  list<string>  $argv
     */
    protected function runProcess(array $argv, ConfigRepository $config, ProcessFactory $process): int
    {
        $timeout = (int) $config->get('gaze.timeout_seconds', 30);

        $result = $process
            ->newPendingProcess()
            ->timeout($timeout)
            ->run($argv);

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

        return self::SUCCESS;
    }

    /**
     * Streaming run: no timeout, pass stdout/stderr chunks through verbatim.
     * Used by `serve` and `logs --follow`.
     *
     * @param  list<string>  $argv
     */
    protected function streamProcess(array $argv, ProcessFactory $process): int
    {
        $result = $process
            ->newPendingProcess()
            ->forever()
            ->run($argv, function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });

        return $result->exitCode() ?? self::FAILURE;
    }

    /**
     * Append `--name=value` when value is a non-empty string.
     *
     * @param  list<string>  $argv
     */
    protected function appendFlag(array &$argv, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $argv[] = "--{$name}={$value}";
        }
    }

    /**
     * Artisan option as non-empty string, or null.
     */
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Config value as non-empty string, or null.
     */
    protected function configString(ConfigRepository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
