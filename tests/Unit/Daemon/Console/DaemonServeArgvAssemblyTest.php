<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Console\Daemon\DaemonServeCommand;
use Illuminate\Config\Repository as ConfigRepository;

/**
 * @param  array<string, mixed>  $daemon
 */
function configRepoForServe(array $daemon): ConfigRepository
{
    return new ConfigRepository(['gaze' => ['daemon' => $daemon]]);
}

/**
 * @param  array<string, string|null>  $options
 */
function commandWithOptions(array $options): DaemonServeCommand
{
    $command = new DaemonServeCommand;
    // Use Reflection to seed the input/options pre-handle for argv assembly.
    // The full Symfony input is not available without artisan; we bypass via
    // a lightweight stub that mimics InputInterface::getOption().
    $stub = new class($options)
    {
        /**
         * @param  array<string, string|null>  $options
         */
        public function __construct(private array $options) {}

        public function getOption(string $name): mixed
        {
            return $this->options[$name] ?? null;
        }
    };

    $reflection = new ReflectionClass($command);
    $inputProp = $reflection->getProperty('input');
    $inputProp->setAccessible(true);
    $inputProp->setValue($command, $stub);

    return $command;
}

it('uses config defaults when no artisan options are passed', function () {
    $config = configRepoForServe([
        'policy_path' => '/etc/gaze/policy.toml',
        'idle_timeout_s' => 1800,
        'audit_db_path' => '/var/lib/gaze/audit.sqlite',
        'binary_path' => null,
    ]);

    $resolver = new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none');
    $command = commandWithOptions([]);

    $argv = $command->buildArgv($resolver, $config);

    expect($argv)->toBe([
        '/fake/gaze',
        'daemon',
        '--policy=/etc/gaze/policy.toml',
        '--idle-timeout=1800',
        '--audit-db=/var/lib/gaze/audit.sqlite',
    ]);
});

it('lets artisan options override config values', function () {
    $config = configRepoForServe([
        'policy_path' => '/etc/gaze/policy.toml',
        'idle_timeout_s' => 1800,
    ]);

    $resolver = new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none');
    $command = commandWithOptions([
        'policy' => '/tmp/override-policy.toml',
        'idle-timeout' => '60',
    ]);

    $argv = $command->buildArgv($resolver, $config);

    expect($argv)->toContain('--policy=/tmp/override-policy.toml');
    expect($argv)->toContain('--idle-timeout=60');
    expect($argv)->not->toContain('--policy=/etc/gaze/policy.toml');
});

it('omits flags whose value is null', function () {
    $config = configRepoForServe([
        'policy_path' => '/etc/gaze/policy.toml',
    ]);

    $resolver = new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none');
    $command = commandWithOptions([]);

    $argv = $command->buildArgv($resolver, $config);

    expect($argv)->toBe(['/fake/gaze', 'daemon', '--policy=/etc/gaze/policy.toml']);
});

it('honors gaze.daemon.binary_path override over BinaryResolver', function () {
    $config = configRepoForServe([
        'policy_path' => '/etc/gaze/policy.toml',
        'binary_path' => '/opt/gaze-override',
    ]);

    $resolver = new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none');
    $command = commandWithOptions([]);

    $argv = $command->buildArgv($resolver, $config);

    expect($argv[0])->toBe('/opt/gaze-override');
});
