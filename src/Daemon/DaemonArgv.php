<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Daemon;

use CertaMesh\Gaze\Exceptions\GazeSafetyNetConfigException;
use CertaMesh\Gaze\GazeOptions;
use CertaMesh\Gaze\SafetyNetBackendGuard;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Single source of truth for the `gaze daemon` flag list.
 *
 * Both spawn paths assemble their argv tail here, so they cannot drift:
 *
 *  - `Gaze::daemon()` — the provider's scoped {@see DaemonClient} binding
 *  - `php artisan gaze:daemon:serve` — the foreground supervisor wrapper
 *
 * Mapping rules (repo principle): a null / empty config key omits the
 * flag entirely so the upstream binary applies its own default. Daemon-
 * specific knobs live under `gaze.daemon.*`; the shared pipeline flags
 * (locale, ner-threshold, safety-net / OPF family) source the SAME
 * top-level `gaze.*` keys the one-shot `Gaze::clean()` path forwards, so
 * a configured pipeline behaves identically in both runtimes.
 */
final class DaemonArgv
{
    /**
     * Assemble the argv tail appended after `gaze daemon`.
     *
     * `$overrides` maps flag name => value for the per-invocation
     * operational knobs `gaze:daemon:serve` exposes as artisan options
     * (`policy`, `idle-timeout`, `session-idle-timeout`, `session-cap`,
     * `audit-db`, `locale`, `ner-threshold`). An override wins over the
     * corresponding config key. All other flags are config-only.
     *
     * @param  array<string, string>  $overrides
     * @return list<string>
     *
     * @throws GazeSafetyNetConfigException when an enabled safety net
     *                                      selects a backend upstream removed
     */
    public static function flags(ConfigRepository $config, array $overrides = []): array
    {
        $argv = [];

        self::append($argv, 'policy', $overrides['policy'] ?? self::string($config, 'gaze.daemon.policy_path'));

        // Safety-net enable + backend selector — sourced from the same
        // top-level `gaze.*` keys the one-shot Gaze::clean() path forwards,
        // so a configured pipeline behaves identically in both runtimes.
        // Mirrors clean(): a truthy gaze.safety_net emits the legacy
        // `--safety-net=openai-filter`; `--safety-net-backend` wins upstream
        // when both are present. The selector is forwarded ONLY with the
        // enable switch — gaze >= 0.15 rejects a lone backend selector with
        // SafetyNetUsage, where 0.12 silently ignored it (net off).
        // Read through GazeOptions — the same coercion Gaze::clean() uses — so
        // the enable switch and selector resolve identically from the flat
        // keys and from a nested `safety_net` group set after the provider
        // normalized config (a raw `(bool)` cast would read a nested
        // `['enabled' => false, …]` array as enabled).
        /** @var array<string, mixed> $gazeConfig */
        $gazeConfig = (array) $config->get('gaze', []);
        $options = GazeOptions::fromConfig($gazeConfig);
        $safetyNet = $options->safetyNet;
        $backend = $options->safetyNetBackend;

        // Same fail-closed pre-flight as Gaze::clean(), so BOTH daemon spawn
        // paths refuse the backend upstream removed in gaze 0.15.0.
        SafetyNetBackendGuard::assertSupported($safetyNet, $backend);

        if ($safetyNet) {
            $argv[] = '--safety-net=openai-filter';
            self::append($argv, 'safety-net-backend', $backend);
        }

        self::append($argv, 'idle-timeout', $overrides['idle-timeout'] ?? self::numeric($config, 'gaze.daemon.idle_timeout_s'));
        self::append($argv, 'session-idle-timeout', $overrides['session-idle-timeout'] ?? self::numeric($config, 'gaze.daemon.session_idle_timeout_s'));
        self::append($argv, 'session-cap', $overrides['session-cap'] ?? self::numeric($config, 'gaze.daemon.session_cap'));
        self::append($argv, 'audit-db', $overrides['audit-db'] ?? self::string($config, 'gaze.daemon.audit_db_path'));
        self::append($argv, 'locale', $overrides['locale'] ?? self::string($config, 'gaze.locale'));
        self::append($argv, 'ner-threshold', $overrides['ner-threshold'] ?? self::numeric($config, 'gaze.ner_threshold'));

        // Policy [ner] artifact overrides — config-only, matching the
        // one-shot posture that artifact paths are deployment config, not
        // per-invocation operational knobs.
        self::append($argv, 'ner-model-dir', self::string($config, 'gaze.daemon.ner_model_dir'));
        self::append($argv, 'ner-locale', self::string($config, 'gaze.daemon.ner_locale'));

        // OpenAI Privacy Filter (Tier 2) backend knobs — top-level `gaze.*`
        // keys shared with the one-shot path. Config-only.
        self::append($argv, 'openai-filter-device', self::string($config, 'gaze.safety_net_device'));
        self::append($argv, 'openai-filter-command', self::string($config, 'gaze.openai_filter_command'));
        self::append($argv, 'openai-filter-checkpoint', self::string($config, 'gaze.openai_filter_checkpoint'));
        self::append($argv, 'openai-filter-operating-point', self::string($config, 'gaze.openai_filter_operating_point'));

        // Safety-net envelope limits + leak handling — top-level `gaze.*`
        // keys shared with the one-shot path. Config-only.
        self::append($argv, 'safety-net-timeout-ms', self::numeric($config, 'gaze.safety_net_timeout_ms'));
        self::append($argv, 'safety-net-input-limit-bytes', self::numeric($config, 'gaze.safety_net_input_limit_bytes'));
        self::append($argv, 'safety-net-mode', self::string($config, 'gaze.safety_net_mode'));
        self::append($argv, 'safety-net-fallback', self::string($config, 'gaze.safety_net_fallback'));

        return $argv;
    }

    /**
     * @param  list<string>  $argv
     */
    private static function append(array &$argv, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $argv[] = "--{$name}={$value}";
        }
    }

    private static function string(ConfigRepository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function numeric(ConfigRepository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_numeric($value) ? (string) $value : null;
    }
}
