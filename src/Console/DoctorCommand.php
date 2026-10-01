<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Console\Concerns\RunsHealthProbes;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetConfigException;
use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\GazeOptions;
use CertaMesh\Gaze\Install\BinaryDownloader;
use CertaMesh\Gaze\Install\NymBundle;
use CertaMesh\Gaze\SafetyNetBackendGuard;
use CertaMesh\Gaze\SessionScopeGuard;
use Devium\Toml\Toml;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;
use Symfony\Component\Console\Formatter\OutputFormatter;

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
        if (! $this->probeSafetyNetBackend($config)) {
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }
        if (! $this->probeNymBundle($config, $policy, $binary)) {
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
            // A failing round-trip is a FAIL row, not an uncaught exception
            // (e.g. a policy-level ephemeral scope answers clean with Pipeline).
            // The exception message carries no input text.
            try {
                $session = $gaze->clean('doctor@example.com');
                $restored = $gaze->restore($session, $session->cleanText);
            } catch (GazeException $e) {
                $this->components->twoColumnDetail('deep', '<fg=red>FAIL</>');
                $this->line($e->getMessage());
                $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

                return self::FAILURE;
            }

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
            'gaze proxy not available — this binary was built without the proxy feature '
            .'(--no-default-features); the gaze:proxy:* commands will error until it has it.'
        );
        // Own short lines so each fix survives console width-wrapping.
        $this->warn('Rebuild with default features: cargo install gaze-cli (add --features safety-net-openai for opf).');
        $this->warn('Or unset GAZE_BINARY and run: php artisan gaze:install:binary --force');
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
            'gaze daemon not available — this binary predates gaze 0.9.0 (there is no daemon cargo '
            .'feature); gaze:daemon:* and Gaze::daemon() will error until it is replaced.'
        );
        // Own short line so the fix survives console width-wrapping.
        $this->warn('Install the pinned binary: unset GAZE_BINARY if set, then php artisan gaze:install:binary --force');
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
     * Backend selector probe: FAILS when the enabled net selects a value
     * gaze does not accept for `--safety-net-backend` — anything but exactly
     * `openai-filter` or `nym` ({@see SafetyNetBackendGuard::ACCEPTED}).
     * Upstream matches the value exactly, so `Nym` or a quoted `" nym"`
     * fails every clean with a detail-less PolicyConfig, and the Nym probe
     * below never runs for it (`nymSelected()` is exact for the same
     * reason). `kiji-distilbert`, in any case, is left to
     * {@see self::probeKijiRemoval()}, which runs first.
     */
    private function probeSafetyNetBackend(ConfigRepository $config): bool
    {
        /** @var array<string, mixed> $gazeConfig */
        $gazeConfig = (array) $config->get('gaze', []);
        $options = GazeOptions::fromConfig($gazeConfig);
        $backend = $options->safetyNetBackend;

        if (! $options->safetyNet || $backend === null
            || SafetyNetBackendGuard::isAccepted($backend) || SafetyNetBackendGuard::isRemoved($backend)) {
            return true;
        }

        // Quoted, so stray whitespace shows.
        $shown = OutputFormatter::escape("'{$backend}'");
        $normalized = strtolower(trim($backend));
        $hint = SafetyNetBackendGuard::isAccepted($normalized)
            ? " Did you mean {$normalized}? gaze matches the value exactly: case and spaces count."
            : '';

        $this->components->twoColumnDetail('safety_net_backend', "<fg=red>unknown {$shown}</>");
        $this->error(
            "GAZE_SAFETY_NET_BACKEND={$shown} is not a backend gaze accepts, so every clean fails with PolicyConfig. "
            .'Use nym or openai-filter.'.$hint
        );

        return false;
    }

    /**
     * Nym bundle probe (gaze >= 0.15.0), active only while the enabled safety
     * net selects `nym` — the state in which the adapter forwards
     * `--safety-net-backend=nym` and the binary loads the bundle on every
     * clean / daemon start.
     *
     * FAILS (P7 doctor-before-failure) when:
     *  - `gaze.safety_net.nym.intra_threads` is not a positive integer, the
     *    pre-flight every clean and daemon start applies
     *    ({@see SafetyNetBackendGuard::assertNymIntraThreads()});
     *  - no bundle directory is configured anywhere: not in
     *    `gaze.safety_net.nym.model_dir`, not in a `GAZE_NYM_MODEL_DIR` the
     *    process environment passes to gaze, not in the policy's
     *    `[safety_net.nym] model_dir` — the binary then fails every clean
     *    with SafetyNetConfig "nym model_dir is missing";
     *  - `GAZE_NYM_MODEL_DIR` is set but empty: upstream uses it as is
     *    (no fallback to the policy) and fails every clean with
     *    SafetyNetArtifactMissing;
     *  - the directory or a required file is missing, a path in it is a
     *    symlink, a path is not owned by the effective uid, the directory is
     *    not mode 0700, or a file is group/world-writable — the binary
     *    refuses the bundle ({@see NymBundle}).
     *
     * The ownership checks hold for the user running doctor, which is often
     * not the PHP-FPM pool / queue worker user that spawns gaze, so every
     * result names the uid it was judged against and a failure tells the
     * adopter to run doctor as that user. Without ext-posix the owner checks
     * cannot run, so the row WARNs instead of claiming OK. The SHA-256
     * digests are left to the binary (`--deep` exercises them).
     */
    private function probeNymBundle(ConfigRepository $config, string $policyPath, string $binary): bool
    {
        /** @var array<string, mixed> $gazeConfig */
        $gazeConfig = (array) $config->get('gaze', []);
        $options = GazeOptions::fromConfig($gazeConfig);
        if (! $options->nymSelected()) {
            return true;
        }

        try {
            SafetyNetBackendGuard::assertNymIntraThreads($options);
        } catch (GazeSafetyNetConfigException $e) {
            // The same refusal every clean and daemon start would hit.
            $this->components->twoColumnDetail('nym intra_threads', '<fg=red>invalid</>');
            $this->error($e->getMessage());

            return false;
        }

        $bundle = $this->laravel->make(NymBundle::class);
        $user = NymBundle::userLabel($bundle->effectiveUid());
        $located = NymBundle::locate($options->nymModelDir, $policyPath);

        if ($located === null) {
            $this->components->twoColumnDetail('nym bundle', '<fg=red>not configured</>');
            $this->error(
                'GAZE_SAFETY_NET_BACKEND=nym, but no Nym bundle directory is configured: gaze fails every clean '
                .'with "nym model_dir is missing". Set GAZE_NYM_MODEL_DIR (gaze.safety_net.nym.model_dir) '
                ."or the policy's [safety_net.nym] model_dir."
            );
            // Own short lines so each command survives console width-wrapping.
            $this->warn('Fetch the bundle as the PHP-FPM pool / queue worker user (replace www-data):');
            $this->warn(NymBundle::setupCommand($binary));
            $this->warn('Then: '.NymBundle::installCommand());

            return false;
        }

        if ($located['dir'] === '') {
            // Upstream takes a set-but-empty GAZE_NYM_MODEL_DIR as is and
            // never reaches the policy, so this is a FAIL even when the
            // policy names a valid bundle.
            $this->components->twoColumnDetail('nym bundle', '<fg=red>GAZE_NYM_MODEL_DIR is empty</>');
            $this->error(NymBundle::EMPTY_ENV);

            return false;
        }

        $problems = $bundle->problems($located['dir']);
        if ($problems === [] && ! $bundle->ownerChecked()) {
            // WARN, exit unchanged: every other check passed, but gaze
            // refuses a bundle its user does not own and that went unchecked.
            $this->components->twoColumnDetail('nym bundle', '<fg=yellow>WARN</> owner not checked (ext-posix missing)');
            $this->warn(
                'Without ext-posix doctor cannot tell who owns the bundle. gaze refuses one that the user running it '
                .'does not own, so check by hand: ls -lnaR '.escapeshellarg($located['dir'])
            );

            return true;
        }
        if ($problems === []) {
            $this->components->twoColumnDetail('nym bundle', "<fg=green>OK</> for {$user}");

            return true;
        }

        $this->components->twoColumnDetail('nym bundle', "<fg=red>refused for {$user}</>");
        $this->error("gaze would refuse the Nym bundle at {$located['dir']} (from {$located['source']}):");
        foreach ($problems as $problem) {
            $this->line("  - {$problem}");
        }
        // Own short lines so each hint survives console width-wrapping.
        $this->warn(
            "These checks ran as {$user}. gaze enforces them for the user that runs it, so run doctor "
            .'as the PHP-FPM pool user, e.g. sudo -u www-data php artisan gaze:doctor.'
        );
        $this->warn('Re-fetch the bundle as that user: '.NymBundle::setupCommand($binary, $located['dir']));
        if (is_dir($located['dir'])) {
            $this->warn('Or hand it to that user: '.NymBundle::chownCommand($located['dir']));
        }

        return false;
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
