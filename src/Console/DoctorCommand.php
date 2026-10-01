<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Console\Concerns\RunsHealthProbes;
use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\GazeOptions;
use CertaMesh\Gaze\Install\BinaryDownloader;
use CertaMesh\Gaze\SafetyNetBackendGuard;
use CertaMesh\Gaze\SessionScopeGuard;
use Devium\Toml\Toml;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;

final class DoctorCommand extends Command
{
    use RunsHealthProbes;

    protected $signature = 'gaze:doctor {--deep : Run a clean/restore smoke test}';

    protected $description = 'Verify binary, policy, encrypter, and optional round-trip readiness.';

    public function handle(BinaryResolver $resolver, ProcessFactory $process, ConfigRepository $config, Gaze $gaze): int
    {
        $binary = $this->probeBinary($resolver);
        if ($binary === null) {
            return self::FAILURE;
        }

        $versionOutput = $this->probeVersion($binary, $process);
        if ($versionOutput === null) {
            return self::FAILURE;
        }

        $this->warnOnPinnedVersionMismatch($versionOutput, $config);

        $policy = (string) $config->get('gaze.policy_path', '');
        $this->components->twoColumnDetail('policy', is_file($policy) ? $policy : '<fg=red>missing</>');
        if (! is_file($policy)) {
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->warnIfDeprecatedRulepack($config, $policy);
        $this->warnIfPolicyPreservesByDefault($policy);
        $this->warnIfRulepacksDropCore($config);
        if (! $this->probeSessionScope($config, $policy)) {
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }
        $this->probeProxyFeature($binary, $config, $process);
        $this->probeDaemonFeature($binary, $config, $process);
        $this->probeRestoreTelemetry($config);
        if (! $this->probeKijiRemoval($config)) {
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $encrypterFailure = $this->probeEncrypter();
        if ($encrypterFailure !== null) {
            $this->components->twoColumnDetail('encrypter', '<fg=red>invalid</>');
            $this->line($encrypterFailure->getMessage());
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('encrypter', '<fg=green>OK</>');
        $this->components->twoColumnDetail('max_bytes', (string) ($config->get('gaze.max_bytes') ?? 10485760));
        $this->components->twoColumnDetail('session_ttl_seconds', (string) ($config->get('gaze.session_ttl_seconds') ?? 86400));

        if ($this->option('deep')) {
            $session = $gaze->clean('doctor@example.com');
            $restored = $gaze->restore($session, $session->cleanText);

            // Both directions: the probe value must leave clean() masked AND
            // come back from restore(). A round-trip alone passes vacuously when
            // nothing is detected (e.g. GAZE_RULEPACKS=none).
            if (str_contains($session->cleanText, 'doctor@example.com') || ! str_contains($restored, 'doctor@example.com')) {
                $this->components->twoColumnDetail('deep', '<fg=red>FAIL</>');
                $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('deep', '<fg=green>OK</>');
        }

        $this->components->twoColumnDetail('status', '<fg=green>OK</>');

        return self::SUCCESS;
    }

    /**
     * P7 doctor-before-failure: surface the post-#139 gap where an installed
     * binary silently lags the package's pinned version after an upgrade.
     *
     * The Composer plugin that re-installed the binary on `composer update` was
     * removed in v0.13.0, so `gaze:install --force` is the only refresh path. A
     * package upgrade that bumps {@see BinaryDownloader::PINNED_VERSION} now
     * leaves the old binary in place with no signal until a runtime feature is
     * missing. Doctor closes that gap here.
     *
     * WARN, never FAIL — a deliberately held-back binary is legitimate, so this
     * follows the warn-without-exit-flip convention of the proxy / daemon /
     * restore-telemetry probes (none of them flip the exit code either):
     *   - `GAZE_BINARY` points at an adopter-built binary (source builds,
     *     unsupported platforms) — the GitHub-release pin does not apply.
     *   - `GAZE_VERSION` pins a different version on purpose.
     * When either opt-out is set the message softens to "expected" and drops the
     * `--force` hint, since the adopter has explicitly opted out of the pin.
     */
    private function warnOnPinnedVersionMismatch(string $versionOutput, ConfigRepository $config): void
    {
        $reported = BinaryDownloader::parseVersion($versionOutput);
        $pinned = BinaryDownloader::PINNED_VERSION;

        // Unparseable output is not this probe's concern (the version detail
        // already shows the raw string); a match is silent, like the other
        // problem-only probes.
        if ($reported === null || $reported === $pinned) {
            return;
        }

        $binary = $config->get('gaze.binary');
        $explicitBinary = is_string($binary) && $binary !== '';
        $explicitVersion = ($env = getenv('GAZE_VERSION')) !== false && $env !== '';

        if ($explicitBinary || $explicitVersion) {
            $optOut = $explicitBinary ? 'GAZE_BINARY' : 'GAZE_VERSION';
            $this->components->twoColumnDetail(
                'pinned version',
                "<fg=yellow>{$reported} (pin v{$pinned}, {$optOut} set)</>"
            );
            $this->warn(
                "gaze binary reports v{$reported} but this package pins v{$pinned}. "
                ."{$optOut} is set, so this mismatch is expected — you have opted out "
                .'of the pin. Ignore if intentional.'
            );

            return;
        }

        $this->components->twoColumnDetail(
            'pinned version',
            "<fg=yellow>{$reported} (expected v{$pinned})</>"
        );
        $this->warn(
            "gaze binary reports v{$reported} but this package pins v{$pinned}. "
            .'A package upgrade bumped the pin without refreshing the binary '
            .'(the Composer auto-install plugin was removed in v0.13.0).'
        );
        // Keep the actionable hint on its own short line so it survives console
        // width-wrapping (a wrapped long line would split the command token).
        $this->warn('Run `php artisan gaze:install --force` to install the pinned binary.');
    }

    private function warnIfDeprecatedRulepack(ConfigRepository $config, string $policyPath): void
    {
        $message = "rulepack 'core-extended' is deprecated as of gaze v0.8.0; aliases to 'core' with a runtime warning. Upstream still ships this soft alias through v0.11.x; removal is deferred (no firm target announced). Pass an explicit --locale (or set GAZE_LOCALE) to retain phone.national.* / postal.* coverage.";

        $rulepacks = $config->get('gaze.rulepacks');
        if (is_array($rulepacks) && in_array('core-extended', $rulepacks, true)) {
            $this->warn($message);

            return;
        }

        try {
            $body = file_get_contents($policyPath);
            if ($body === false) {
                throw new \RuntimeException('could not read file');
            }

            /** @var array<string, mixed> $parsed */
            $parsed = Toml::decode($body, asArray: true);
        } catch (\Throwable $e) {
            $this->warn(
                "policy at {$policyPath} could not be parsed as TOML ({$e->getMessage()}) — "
                .'skipping deprecated-rulepack check. The gaze binary will likely reject this policy too.'
            );

            return;
        }

        $bundled = $parsed['policy']['rulepacks']['bundled'] ?? null;
        if (is_array($bundled) && in_array('core-extended', $bundled, true)) {
            $this->warn($message);
        }
    }

    /**
     * WARN (never fail) when the configured policy's fall-through rule sends
     * detected classes to the model raw: a `kind = "default"` rule with
     * `action = "preserve"`, or no default rule at all (upstream then
     * preserves). gaze >= 0.15 prints the same finding on stderr — but only
     * on a successful clean, whose stderr the adapter discards, so without
     * this probe an app's own policy.toml copy keeps leaking silently after
     * the shipped policy switched to `tokenize` (v0.14.0). An unparseable
     * policy is skipped here; warnIfDeprecatedRulepack() already reports it.
     */
    private function warnIfPolicyPreservesByDefault(string $policyPath): void
    {
        $parsed = $this->decodePolicy($policyPath);
        if ($parsed === null) {
            return;
        }

        $rules = is_array($parsed['rule'] ?? null) ? $parsed['rule'] : [];
        $defaultAction = null;
        foreach ($rules as $rule) {
            if (is_array($rule) && ($rule['kind'] ?? null) === 'default') {
                $defaultAction = is_string($rule['action'] ?? null) ? $rule['action'] : null;
            }
        }

        if ($defaultAction !== null && $defaultAction !== 'preserve') {
            return;
        }

        $this->components->twoColumnDetail('policy default', '<fg=yellow>'.($defaultAction ?? 'missing (preserve)').'</>');
        $this->warn(
            'The policy\'s fall-through rule preserves: every detected class without its own [[rule]] '
            .'(national IDs, dates of birth, URLs, ...) reaches the model raw.'
        );
        // Own short line so the fix survives console width-wrapping.
        $this->warn('Set the default rule to action = "tokenize" (UPGRADING.md, v0.14.0).');
    }

    /**
     * `Gaze::clean()` cannot run under an ephemeral session scope: gaze clean
     * must export the session blob, and gaze never exports an ephemeral
     * session ({@see SessionScopeGuard}).
     *
     * FAILS when `gaze.session_scope` is ephemeral — the same pre-flight
     * `Gaze::clean()` applies, surfaced here first. Returns false to flip
     * doctor's exit.
     *
     * WARNS, never fails, when no override is set and the policy's
     * `[session] scope` is ephemeral: the binary then fails every clean with
     * the Retryable Pipeline error, which the adapter cannot pre-flight at
     * runtime. A conversation / persistent override wins over the policy, so
     * it silences the warning. The daemon never exports and is unaffected.
     */
    private function probeSessionScope(ConfigRepository $config, string $policyPath): bool
    {
        $override = $config->get('gaze.session_scope');
        if (is_string($override) && $override !== '') {
            if (! SessionScopeGuard::isEphemeral($override)) {
                return true;
            }

            $this->components->twoColumnDetail('session_scope', '<fg=red>ephemeral (unsupported by clean)</>');
            $this->error(SessionScopeGuard::EPHEMERAL_UNSUPPORTED);

            return false;
        }

        $scope = ($this->decodePolicy($policyPath) ?? [])['session']['scope'] ?? null;
        if ($scope !== SessionScopeGuard::EPHEMERAL) {
            return true;
        }

        $this->components->twoColumnDetail('policy session scope', '<fg=yellow>ephemeral</>');
        $this->warn(
            'The policy\'s [session] scope = "ephemeral" fails every Gaze::clean() with GazePipelineException, '
            .'which queue jobs retry: gaze clean cannot export an ephemeral session blob.'
        );
        // Own short line so the fix survives console width-wrapping.
        $this->warn('Set scope = "conversation" or "persistent", or override it with GAZE_SESSION_SCOPE.');

        return true;
    }

    /**
     * Best-effort TOML decode of the policy for the probes above. Null when
     * the file cannot be read or parsed; warnIfDeprecatedRulepack() already
     * reports an unparseable policy.
     *
     * @return array<string, mixed>|null
     */
    private function decodePolicy(string $policyPath): ?array
    {
        $body = @file_get_contents($policyPath);
        if ($body === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $parsed */
            $parsed = Toml::decode($body, asArray: true);
        } catch (\Throwable) {
            return null;
        }

        return $parsed;
    }

    /**
     * WARN (never fail) when `gaze.rulepacks` overrides the policy's bundled
     * list without `core`. The override REPLACES the list, so e.g.
     * `GAZE_RULEPACKS=secrets` drops emails, phones, IBANs and cards, and
     * since gaze 0.15 `GAZE_RULEPACKS=none` is accepted and runs no bundled
     * pack at all (0.12 rejected it) — every clean succeeds with no detection.
     */
    private function warnIfRulepacksDropCore(ConfigRepository $config): void
    {
        $rulepacks = $config->get('gaze.rulepacks');
        if (! is_array($rulepacks) || $rulepacks === [] || in_array('core', $rulepacks, true) || in_array('core-extended', $rulepacks, true)) {
            return;
        }

        $this->components->twoColumnDetail('rulepacks', '<fg=yellow>'.implode(',', array_filter($rulepacks, is_string(...))).' (no core)</>');
        $this->warn(
            'GAZE_RULEPACKS replaces the policy\'s bundled rulepacks and does not include core: '
            .'emails, phones, IBANs, cards and IPs reach the model raw.'
        );
        $this->warn('Keep core in the list, e.g. GAZE_RULEPACKS=core,secrets.');
    }

    /**
     * Best-effort probe for the upstream `proxy` feature build flag.
     *
     * Skipped when the adopter has not deviated from the package's default
     * `gaze.proxy.*` block — keeps doctor output noise-free for the majority
     * who never use the proxy. When the adopter HAS configured proxy and the
     * installed binary lacks the feature, surface the exact `cargo install`
     * hint upstream documents.
     */
    private function probeProxyFeature(string $binary, ConfigRepository $config, ProcessFactory $process): void
    {
        if (! $this->proxyExplicitlyConfigured($config)) {
            return;
        }

        $result = $process->newPendingProcess()->timeout(3)->run([$binary, 'proxy', '--help']);
        $stderr = $result->errorOutput();

        if ($result->successful() && ! str_contains($stderr, 'unknown subcommand')) {
            $this->info('gaze proxy feature available');

            return;
        }

        $this->warn(
            'gaze proxy not available — rebuild upstream binary with: '
            .'cargo install gaze-cli --features proxy. '
            .'Adapter v0.8.1 proxy artisan commands will error on invocation.'
        );
    }

    /**
     * Pre-flight probe for the upstream `daemon` feature build flag.
     *
     * Skipped when `gaze.daemon.policy_path` is null — that key is the
     * opt-in signal that the adopter intends to use daemon mode. When
     * populated, surfaces the exact `cargo install` hint if the binary
     * lacks the subverb.
     */
    private function probeDaemonFeature(string $binary, ConfigRepository $config, ProcessFactory $process): void
    {
        $policyPath = $config->get('gaze.daemon.policy_path');
        if (! is_string($policyPath) || $policyPath === '') {
            return;
        }

        if (! is_file($policyPath)) {
            $this->components->twoColumnDetail('daemon policy', '<fg=red>missing</>');
            $this->warn("gaze.daemon.policy_path={$policyPath} does not exist.");
        } else {
            $this->components->twoColumnDetail('daemon policy', $policyPath);
        }

        $auditDb = $config->get('gaze.daemon.audit_db_path');
        if (is_string($auditDb) && $auditDb !== '') {
            $parent = dirname($auditDb);
            if (! is_dir($parent) || ! is_writable($parent)) {
                $this->warn("gaze.daemon.audit_db_path parent {$parent} is not writable.");
            }
        }

        $stderrPath = $config->get('gaze.daemon.stderr_path');
        if (is_string($stderrPath) && $stderrPath !== '') {
            $parent = dirname($stderrPath);
            if (! is_dir($parent) || ! is_writable($parent)) {
                $this->warn("gaze.daemon.stderr_path parent {$parent} is not writable.");
            }
        }

        $result = $process->newPendingProcess()->timeout(3)->run([$binary, 'daemon', '--help']);
        $stderr = $result->errorOutput();

        if ($result->successful() && ! str_contains($stderr, 'unknown subcommand')) {
            $this->info('gaze daemon feature available');

            return;
        }

        $this->warn(
            'gaze daemon not available — rebuild upstream binary with: '
            .'cargo install gaze-cli --features daemon. '
            .'Adapter v0.11.0 daemon artisan commands and Gaze::daemon() Facade will error on invocation.'
        );
    }

    /**
     * Upstream removed the Kiji DistilBERT safety net (and every `--kiji-*`
     * flag) in gaze 0.15.0.
     *
     * FAILS when an ENABLED safety net still selects `kiji-distilbert` — the
     * same fail-closed guard `Gaze::clean()` and both daemon spawn paths
     * apply before spawning ({@see SafetyNetBackendGuard}), surfaced here
     * first (P7 doctor-before-failure). Returns false to flip doctor's exit.
     *
     * WARNS, never fails, on leftover Kiji config the adapter now ignores: a
     * `kiji-distilbert` selector on a disabled net, the published config's
     * nested `safety_net.kiji.*` group or pre-v0.13 flat `kiji_*` keys,
     * `daemon.kiji_distilbert_locales`, and the `GAZE_KIJI_*` env vars that
     * fed them. The env vars are checked directly because the provider
     * collapses the nested `safety_net` group at boot, so a v0.13-shaped
     * published config's Kiji values never reach the runtime config.
     */
    private function probeKijiRemoval(ConfigRepository $config): bool
    {
        /** @var array<string, mixed> $gazeConfig */
        $gazeConfig = (array) $config->get('gaze', []);
        $options = GazeOptions::fromConfig($gazeConfig);
        $kijiSelected = SafetyNetBackendGuard::isRemoved($options->safetyNetBackend);

        if ($kijiSelected && $options->safetyNet) {
            $this->components->twoColumnDetail('safety_net_backend', '<fg=red>kiji-distilbert removed in gaze 0.15.0</>');
            $this->error(SafetyNetBackendGuard::KIJI_DISTILBERT_REMOVED);

            return false;
        }

        $leftovers = $kijiSelected ? ['gaze.safety_net.backend=kiji-distilbert (net disabled)'] : [];

        $nested = $config->get('gaze.safety_net');
        if (is_array($nested) && is_array($nested['kiji'] ?? null)) {
            foreach ($nested['kiji'] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $leftovers[] = "gaze.safety_net.kiji.{$key}";
                }
            }
        }

        foreach (['gaze.kiji_backend', 'gaze.kiji_distilbert_precision', 'gaze.kiji_distilbert_command', 'gaze.kiji_distilbert_model_dir', 'gaze.daemon.kiji_distilbert_locales'] as $key) {
            $value = $config->get($key);
            if ($value !== null && $value !== '') {
                $leftovers[] = $key;
            }
        }

        foreach (['GAZE_KIJI_BACKEND', 'GAZE_KIJI_DISTILBERT_PRECISION', 'GAZE_KIJI_DISTILBERT_COMMAND', 'GAZE_KIJI_DISTILBERT_MODEL_DIR', 'GAZE_DAEMON_KIJI_DISTILBERT_LOCALES'] as $env) {
            $value = getenv($env);
            if ($value !== false && $value !== '') {
                $leftovers[] = $env;
            }
        }

        if ($leftovers === []) {
            return true;
        }

        $this->components->twoColumnDetail('kiji config', '<fg=yellow>ignored</>');
        $this->warn(
            'Kiji DistilBERT config is ignored — upstream removed the backend in gaze 0.15.0: '
            .implode(', ', array_unique($leftovers)).'.'
        );
        // Own short line so the hint survives console width-wrapping.
        $this->warn('Remove it (see UPGRADING.md); for a safety net, switch to nym.');

        return true;
    }

    /**
     * Pre-flight probe for restore-telemetry audit-db writability.
     *
     * Skipped silently unless `gaze.restore_telemetry` is enabled — that key is
     * the opt-in signal (P7 doctor-before-failure, but only when opted in). When
     * enabled, asserts `gaze.audit_db_path` is set and its parent dir is
     * writable; WARNS (never hard-fails) when missing/unwritable so the adopter
     * learns restore-telemetry rows cannot be written before the first restore.
     */
    private function probeRestoreTelemetry(ConfigRepository $config): void
    {
        if (! $config->get('gaze.restore_telemetry')) {
            return;
        }

        $auditDb = $config->get('gaze.audit_db_path');
        if (! is_string($auditDb) || $auditDb === '') {
            $this->components->twoColumnDetail('restore_telemetry', '<fg=yellow>no audit-db</>');
            $this->warn(
                'gaze.restore_telemetry is enabled but gaze.audit_db_path (env '
                .'GAZE_AUDIT_DB_PATH) is not set — restore-telemetry rows cannot be '
                .'written. Set the audit-db path to capture them.'
            );

            return;
        }

        $parent = dirname($auditDb);
        if (! is_dir($parent) || ! is_writable($parent)) {
            $this->components->twoColumnDetail('restore_telemetry', '<fg=yellow>unwritable</>');
            $this->warn(
                "gaze.audit_db_path parent {$parent} is not writable — "
                .'restore-telemetry rows cannot be written.'
            );

            return;
        }

        $this->components->twoColumnDetail('restore_telemetry', '<fg=green>OK</>');
    }

    private function proxyExplicitlyConfigured(ConfigRepository $config): bool
    {
        $proxy = $config->get('gaze.proxy');
        if (! is_array($proxy)) {
            return false;
        }

        $policyPath = $proxy['policy_path'] ?? null;
        if (is_string($policyPath) && $policyPath !== '') {
            return true;
        }

        $defaults = [
            'bind' => '127.0.0.1:8787',
            'session_ttl' => '30m',
            'rulepack' => 'core',
            'stop_timeout' => '10s',
        ];
        foreach ($defaults as $key => $default) {
            if (($proxy[$key] ?? $default) !== $default) {
                return true;
            }
        }

        $upstreamDefaults = [
            'openai' => 'https://api.openai.com/',
            'anthropic' => 'https://api.anthropic.com/',
            'gemini' => 'https://generativelanguage.googleapis.com/',
        ];
        $upstream = $proxy['upstream'] ?? [];
        if (! is_array($upstream)) {
            return false;
        }
        foreach ($upstreamDefaults as $key => $default) {
            if (($upstream[$key] ?? $default) !== $default) {
                return true;
            }
        }

        return false;
    }
}
