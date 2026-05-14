<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;
use Naoray\GazeLaravel\Gaze;
use Yosymfony\Toml\Toml;

final class DoctorCommand extends Command
{
    protected $signature = 'gaze:doctor {--deep : Run a clean/restore smoke test}';

    protected $description = 'Verify binary, policy, encrypter, and optional round-trip readiness.';

    public function handle(BinaryResolver $resolver, ProcessFactory $process, ConfigRepository $config, Gaze $gaze): int
    {
        try {
            $binary = $resolver->resolve();
        } catch (GazeBinaryMissingException $e) {
            $this->components->twoColumnDetail('binary', '<fg=red>missing</>');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('binary', $binary);

        $version = $process->newPendingProcess()->timeout(5)->run([$binary, '--version']);
        if (! $version->successful()) {
            $this->components->twoColumnDetail('version', '<fg=red>unknown</>');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('version', trim($version->output()));

        $policy = (string) $config->get('gaze.policy_path', '');
        $this->components->twoColumnDetail('policy', is_file($policy) ? $policy : '<fg=red>missing</>');
        if (! is_file($policy)) {
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->warnIfDeprecatedRulepack($config, $policy);
        $this->probeProxyFeature($binary, $config, $process);

        try {
            $this->laravel->make('gaze.encrypter');
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('encrypter', '<fg=red>invalid</>');
            $this->line($e->getMessage());
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('encrypter', '<fg=green>OK</>');
        $this->components->twoColumnDetail('max_bytes', (string) ($config->get('gaze.max_bytes') ?? 10485760));
        $this->components->twoColumnDetail('session_ttl_seconds', (string) ($config->get('gaze.session_ttl_seconds') ?? 86400));

        if ($this->option('deep')) {
            $session = $gaze->clean('doctor@example.com');
            $restored = $gaze->restore($session, $session->cleanText);

            if (! str_contains($restored, 'doctor@example.com')) {
                $this->components->twoColumnDetail('deep', '<fg=red>FAIL</>');
                $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('deep', '<fg=green>OK</>');
        }

        $this->components->twoColumnDetail('status', '<fg=green>OK</>');

        return self::SUCCESS;
    }

    private function warnIfDeprecatedRulepack(ConfigRepository $config, string $policyPath): void
    {
        $message = "rulepack 'core-extended' is deprecated as of gaze v0.8.0; aliases to 'core' with a runtime warning. Removal target: v0.10.0. Pass an explicit --locale (or set GAZE_LOCALE) to retain phone.national.* / postal.* coverage.";

        $rulepacks = $config->get('gaze.rulepacks');
        if (is_array($rulepacks) && in_array('core-extended', $rulepacks, true)) {
            $this->warn($message);

            return;
        }

        try {
            $parsed = Toml::parseFile($policyPath);
        } catch (\Throwable) {
            return;
        }

        $bundled = $parsed['policy']['rulepacks']['bundled'] ?? null;
        if (is_array($bundled) && in_array('core-extended', $bundled, true)) {
            $this->warn($message);
        }
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
