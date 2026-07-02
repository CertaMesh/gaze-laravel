<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Console\Daemon\DaemonServeCommand;
use Illuminate\Config\Repository as ConfigRepository;

/**
 * @param  array<string, mixed>  $daemon
 * @param  array<string, mixed>  $topLevel  Top-level `gaze.*` keys (one-shot pipeline config).
 */
function configRepoForServe(array $daemon, array $topLevel = []): ConfigRepository
{
    return new ConfigRepository(['gaze' => array_merge($topLevel, ['daemon' => $daemon])]);
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

it('forwards daemon session-tuning and NER flags from config', function () {
    $config = configRepoForServe(
        daemon: [
            'policy_path' => '/etc/gaze/policy.toml',
            'session_idle_timeout_s' => 3600,
            'session_cap' => 500,
            'ner_model_dir' => '/opt/gaze/ner-model',
            'ner_locale' => 'de',
        ],
        topLevel: [
            'locale' => 'de,en',
            'ner_threshold' => 0.75,
        ],
    );

    $resolver = new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none');
    $command = commandWithOptions([]);

    $argv = $command->buildArgv($resolver, $config);

    expect($argv)->toBe([
        '/fake/gaze',
        'daemon',
        '--policy=/etc/gaze/policy.toml',
        '--session-idle-timeout=3600',
        '--session-cap=500',
        '--locale=de,en',
        '--ner-threshold=0.75',
        '--ner-model-dir=/opt/gaze/ner-model',
        '--ner-locale=de',
    ]);
});

it('forwards the safety-net flag family from top-level gaze config, mirroring the one-shot path', function () {
    $config = configRepoForServe(
        daemon: [
            'policy_path' => '/etc/gaze/policy.toml',
            'kiji_distilbert_locales' => 'de,fr',
        ],
        topLevel: [
            'safety_net' => true,
            'safety_net_backend' => 'kiji-distilbert',
            'safety_net_device' => 'cpu',
            'openai_filter_command' => '/usr/local/bin/opf',
            'openai_filter_checkpoint' => '/opt/opf/checkpoint',
            'openai_filter_operating_point' => 'high-recall',
            'kiji_backend' => 'ort',
            'kiji_distilbert_command' => '/usr/local/bin/kiji',
            'kiji_distilbert_model_dir' => '/opt/kiji/model',
            'safety_net_timeout_ms' => 7500,
            'safety_net_input_limit_bytes' => 2097152,
            'safety_net_mode' => 'strict',
            'safety_net_fallback' => 'redact',
        ],
    );

    $resolver = new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none');
    $command = commandWithOptions([]);

    $argv = $command->buildArgv($resolver, $config);

    expect($argv)->toBe([
        '/fake/gaze',
        'daemon',
        '--policy=/etc/gaze/policy.toml',
        '--safety-net=openai-filter',
        '--safety-net-backend=kiji-distilbert',
        '--openai-filter-device=cpu',
        '--openai-filter-command=/usr/local/bin/opf',
        '--openai-filter-checkpoint=/opt/opf/checkpoint',
        '--openai-filter-operating-point=high-recall',
        '--kiji-backend=ort',
        '--kiji-distilbert-command=/usr/local/bin/kiji',
        '--kiji-distilbert-model-dir=/opt/kiji/model',
        '--kiji-distilbert-locales=de,fr',
        '--safety-net-timeout-ms=7500',
        '--safety-net-input-limit-bytes=2097152',
        '--safety-net-mode=strict',
        '--safety-net-fallback=redact',
    ]);
});

it('omits --safety-net when gaze.safety_net is false', function () {
    $config = configRepoForServe(
        daemon: ['policy_path' => '/etc/gaze/policy.toml'],
        topLevel: ['safety_net' => false],
    );

    $resolver = new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none');
    $command = commandWithOptions([]);

    $argv = $command->buildArgv($resolver, $config);

    expect($argv)->toBe(['/fake/gaze', 'daemon', '--policy=/etc/gaze/policy.toml']);
});

it('lets artisan options override session-tuning, locale, and ner-threshold config', function () {
    $config = configRepoForServe(
        daemon: [
            'policy_path' => '/etc/gaze/policy.toml',
            'session_idle_timeout_s' => 3600,
            'session_cap' => 500,
        ],
        topLevel: [
            'locale' => 'de',
            'ner_threshold' => 0.5,
        ],
    );

    $resolver = new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none');
    $command = commandWithOptions([
        'session-idle-timeout' => '120',
        'session-cap' => '25',
        'locale' => 'en',
        'ner-threshold' => '0.9',
    ]);

    $argv = $command->buildArgv($resolver, $config);

    expect($argv)->toContain('--session-idle-timeout=120')
        ->toContain('--session-cap=25')
        ->toContain('--locale=en')
        ->toContain('--ner-threshold=0.9')
        ->not->toContain('--session-idle-timeout=3600')
        ->not->toContain('--session-cap=500')
        ->not->toContain('--locale=de')
        ->not->toContain('--ner-threshold=0.5');
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
