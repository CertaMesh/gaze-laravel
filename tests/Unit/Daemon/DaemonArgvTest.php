<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonArgv;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetConfigException;
use CertaMesh\Gaze\SafetyNetBackendGuard;
use Illuminate\Config\Repository as ConfigRepository;

/**
 * @param  array<string, mixed>  $daemon
 * @param  array<string, mixed>  $topLevel  Top-level `gaze.*` keys (one-shot pipeline config).
 */
function configRepoForArgv(array $daemon = [], array $topLevel = []): ConfigRepository
{
    return new ConfigRepository(['gaze' => array_merge($topLevel, ['daemon' => $daemon])]);
}

it('returns an empty flag list when nothing is configured', function () {
    expect(DaemonArgv::flags(configRepoForArgv()))->toBe([]);
});

it('omits flags whose config value is null so upstream defaults apply', function () {
    $config = configRepoForArgv(daemon: [
        'policy_path' => '/etc/gaze/policy.toml',
        'idle_timeout_s' => null,
        'session_idle_timeout_s' => null,
        'session_cap' => null,
        'audit_db_path' => null,
        'ner_model_dir' => null,
        'ner_locale' => null,
        'kiji_distilbert_locales' => null,
    ]);

    expect(DaemonArgv::flags($config))->toBe(['--policy=/etc/gaze/policy.toml']);
});

it('assembles the full daemon flag surface from config in pinned order, never emitting --kiji-*', function () {
    $config = configRepoForArgv(
        daemon: [
            'policy_path' => '/etc/gaze/policy.toml',
            'idle_timeout_s' => 1800,
            'session_idle_timeout_s' => 3600,
            'session_cap' => 500,
            'audit_db_path' => '/var/lib/gaze/audit.sqlite',
            'ner_model_dir' => '/opt/gaze/ner-model',
            'ner_locale' => 'de',
            // Leftover Kiji keys (removed upstream in gaze 0.15.0): ignored.
            'kiji_distilbert_locales' => 'de,fr',
        ],
        topLevel: [
            'safety_net' => true,
            'safety_net_backend' => 'nym',
            'locale' => 'de,en',
            'ner_threshold' => 0.75,
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
            'nym_model_dir' => '/srv/gaze/gaze/models/nym-small-int8',
            'nym_intra_threads' => 2,
        ],
    );

    expect(DaemonArgv::flags($config))->toBe([
        '--policy=/etc/gaze/policy.toml',
        '--safety-net=openai-filter',
        '--safety-net-backend=nym',
        '--idle-timeout=1800',
        '--session-idle-timeout=3600',
        '--session-cap=500',
        '--audit-db=/var/lib/gaze/audit.sqlite',
        '--locale=de,en',
        '--ner-threshold=0.75',
        '--ner-model-dir=/opt/gaze/ner-model',
        '--ner-locale=de',
        '--openai-filter-device=cpu',
        '--openai-filter-command=/usr/local/bin/opf',
        '--openai-filter-checkpoint=/opt/opf/checkpoint',
        '--openai-filter-operating-point=high-recall',
        '--safety-net-timeout-ms=7500',
        '--safety-net-input-limit-bytes=2097152',
        '--safety-net-mode=strict',
        '--safety-net-fallback=redact',
        '--nym-model-dir=/srv/gaze/gaze/models/nym-small-int8',
        '--nym-intra-threads=2',
    ]);
});

it('omits --safety-net when gaze.safety_net is false', function () {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '/etc/gaze/policy.toml'],
        topLevel: ['safety_net' => false],
    );

    expect(DaemonArgv::flags($config))->toBe(['--policy=/etc/gaze/policy.toml']);
});

it('omits --safety-net-backend when gaze.safety_net is false (gaze >= 0.15 rejects a lone selector)', function () {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '/etc/gaze/policy.toml'],
        topLevel: [
            'safety_net' => false,
            'safety_net_backend' => 'openai-filter',
            'safety_net_mode' => 'strict',
        ],
    );

    expect(DaemonArgv::flags($config))->toBe([
        '--policy=/etc/gaze/policy.toml',
        '--safety-net-mode=strict',
    ]);
});

it('refuses an enabled kiji-distilbert backend before any argv is built', function () {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '/etc/gaze/policy.toml'],
        topLevel: ['safety_net' => true, 'safety_net_backend' => 'kiji-distilbert'],
    );

    try {
        DaemonArgv::flags($config);
    } catch (GazeSafetyNetConfigException $e) {
        expect($e->getMessage())->toBe(SafetyNetBackendGuard::KIJI_DISTILBERT_REMOVED)
            ->and($e->exitCode)->toBe(2)
            ->and($e->stderrHash)->toBeNull();

        return;
    }

    $this->fail('Expected GazeSafetyNetConfigException to be thrown.');
});

it('refuses kiji-distilbert regardless of case and surrounding whitespace', function (string $backend) {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '/etc/gaze/policy.toml'],
        topLevel: ['safety_net' => true, 'safety_net_backend' => $backend],
    );

    expect(fn () => DaemonArgv::flags($config))->toThrow(GazeSafetyNetConfigException::class);
})->with(['mixed case' => ['Kiji-Distilbert'], 'padded' => [' kiji-distilbert ']]);

it('reads the safety-net switch through GazeOptions so a nested group set at runtime is honoured', function (array $safetyNet, ?string $throws, array $expected) {
    // A nested `safety_net` group set after the provider normalized config
    // (runtime config()->set): a raw (bool) cast would read any non-empty
    // array as "enabled" and miss the backend selector entirely.
    $config = configRepoForArgv(
        daemon: ['policy_path' => '/etc/gaze/policy.toml'],
        topLevel: ['safety_net' => $safetyNet],
    );

    if ($throws !== null) {
        expect(fn () => DaemonArgv::flags($config))->toThrow($throws);

        return;
    }

    expect(DaemonArgv::flags($config))->toBe($expected);
})->with([
    'enabled kiji fails closed' => [['enabled' => true, 'backend' => 'kiji-distilbert'], GazeSafetyNetConfigException::class, []],
    'disabled stays off' => [['enabled' => false, 'backend' => 'nym'], null, ['--policy=/etc/gaze/policy.toml']],
    'enabled nym forwards both' => [['enabled' => true, 'backend' => 'nym'], null, ['--policy=/etc/gaze/policy.toml', '--safety-net=openai-filter', '--safety-net-backend=nym']],
]);

it('ignores a leftover kiji-distilbert selector while the net is disabled', function () {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '/etc/gaze/policy.toml'],
        topLevel: ['safety_net' => false, 'safety_net_backend' => 'kiji-distilbert'],
    );

    expect(DaemonArgv::flags($config))->toBe(['--policy=/etc/gaze/policy.toml']);
});

it('forwards the shared rulepack lists after the NER overrides, one flag per element', function () {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '/etc/gaze/policy.toml', 'ner_locale' => 'de'],
        topLevel: ['rulepacks' => ['core', 'secrets'], 'rulepack_paths' => ['/etc/gaze/tenant.toml']],
    );

    expect(DaemonArgv::flags($config))->toBe([
        '--policy=/etc/gaze/policy.toml',
        '--ner-locale=de',
        '--rulepack-bundled=core',
        '--rulepack-bundled=secrets',
        '--rulepack-path=/etc/gaze/tenant.toml',
    ]);
});

it('lets caller overrides win over config for the operational knobs', function () {
    $config = configRepoForArgv(
        daemon: [
            'policy_path' => '/etc/gaze/policy.toml',
            'idle_timeout_s' => 1800,
            'session_idle_timeout_s' => 3600,
            'session_cap' => 500,
            'audit_db_path' => '/var/lib/gaze/audit.sqlite',
        ],
        topLevel: [
            'locale' => 'de',
            'ner_threshold' => 0.5,
        ],
    );

    $argv = DaemonArgv::flags($config, [
        'policy' => '/tmp/override.toml',
        'idle-timeout' => '60',
        'session-idle-timeout' => '120',
        'session-cap' => '25',
        'audit-db' => '/tmp/audit.sqlite',
        'locale' => 'en',
        'ner-threshold' => '0.9',
    ]);

    expect($argv)->toBe([
        '--policy=/tmp/override.toml',
        '--idle-timeout=60',
        '--session-idle-timeout=120',
        '--session-cap=25',
        '--audit-db=/tmp/audit.sqlite',
        '--locale=en',
        '--ner-threshold=0.9',
    ]);
});

it('treats empty-string config values as unset', function () {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '', 'audit_db_path' => ''],
        topLevel: ['locale' => ''],
    );

    expect(DaemonArgv::flags($config))->toBe([]);
});

it('keeps a zero ner-threshold (numeric, not truthy) in the flag list', function () {
    $config = configRepoForArgv(topLevel: ['ner_threshold' => 0]);

    expect(DaemonArgv::flags($config))->toBe(['--ner-threshold=0']);
});

it('forwards the nym knobs only while the enabled net selects nym (gaze >= 0.15 rejects them otherwise)', function () {
    // gaze 0.15.1 with the net off: exit 3 SafetyNetConfig "safety-net
    // backend options require --safety-net=<kind> activation".
    $flags = fn (array $topLevel): array => DaemonArgv::flags(configRepoForArgv(
        topLevel: array_merge(['nym_model_dir' => '/srv/nym', 'nym_intra_threads' => '3'], $topLevel),
    ));

    expect($flags(['safety_net' => true, 'safety_net_backend' => 'nym']))
        ->toBe(['--safety-net=openai-filter', '--safety-net-backend=nym', '--nym-model-dir=/srv/nym', '--nym-intra-threads=3'])
        ->and($flags(['safety_net' => false, 'safety_net_backend' => 'nym']))->toBe([])
        ->and($flags(['safety_net' => true, 'safety_net_backend' => 'openai-filter']))
        ->toBe(['--safety-net=openai-filter', '--safety-net-backend=openai-filter'])
        ->and($flags(['safety_net' => true]))->toBe(['--safety-net=openai-filter']);
});

it('reads the nym knobs from a nested safety_net group set at runtime', function () {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '/etc/gaze/policy.toml'],
        topLevel: ['safety_net' => [
            'enabled' => true,
            'backend' => 'nym',
            'nym' => ['model_dir' => '/srv/nym', 'intra_threads' => 2],
        ]],
    );

    expect(DaemonArgv::flags($config))->toBe([
        '--policy=/etc/gaze/policy.toml',
        '--safety-net=openai-filter',
        '--safety-net-backend=nym',
        '--nym-model-dir=/srv/nym',
        '--nym-intra-threads=2',
    ]);
});

it('refuses a non-positive nym intra_threads on an enabled nym net before any argv is built', function () {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '/etc/gaze/policy.toml'],
        topLevel: ['safety_net' => true, 'safety_net_backend' => 'nym', 'nym_intra_threads' => '0'],
    );

    expect(fn () => DaemonArgv::flags($config))
        ->toThrow(GazeSafetyNetConfigException::class, 'gaze.safety_net.nym.intra_threads must be a positive integer, got 0 (pre-flight)');
});
