<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Install;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Console\Concerns\BuildsBinaryArgv;
use CertaMesh\Gaze\GazeOptions;
use CertaMesh\Gaze\Install\NymBundle;
use CertaMesh\Gaze\Install\SafetyNetConfigStatus;
use CertaMesh\Gaze\Install\SafetyNetConfigurator;
use CertaMesh\Gaze\Install\SafetyNetConfiguratorResult;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Wire a gaze safety-net backend into `.env` idempotently.
 *
 *   - `nym`  (gaze >= 0.15.0, compiled into the release binary) — the pinned
 *     bundle is validated BEFORE the write (CB4) with the same
 *     {@see NymBundle} checks `gaze:doctor` runs, so a fresh-process doctor
 *     never fails on a wiring we just wrote. The bundle is fetched by upstream
 *     `gaze setup --safety-net nym`, never downloaded here; when it is missing
 *     the command prints that exact command instead.
 *   - `opf`  (Tier 2, openai-filter) — a LOCAL subprocess; we wire the optional
 *     command/checkpoint paths and warn that doctor cannot verify the subprocess.
 *
 * The `kiji` backend is gone: upstream removed Kiji DistilBERT in gaze 0.15.0,
 * so `--safety-net=kiji` fails before anything is written.
 */
final class InstallSafetyNetCommand extends Command
{
    use BuildsBinaryArgv;

    /** Shared with the `gaze:install` umbrella, which rejects kiji up front. */
    public const KIJI_REMOVED = 'the kiji safety-net backend was removed upstream in gaze 0.15.0. '
        .'Use --safety-net=nym, its replacement, which is compiled into the release binary. '
        .'(opf needs a gaze binary built with the safety-net-openai feature; the pinned release binary is not.)';

    protected $signature = 'gaze:install:safety-net
        {--safety-net= : Backend to wire non-interactively: nym|opf}
        {--nym-model-dir= : Nym bundle directory from `gaze setup --safety-net nym` (nym backend only)}
        {--opf-command= : Path to the local opf subprocess binary (opf backend only)}
        {--opf-checkpoint= : opf model checkpoint directory (opf backend only)}
        {--force : Overwrite existing safety-net env keys}
        {--print : Print the env lines instead of writing .env}';

    protected $description = 'Wire a gaze safety-net backend (nym | opf) into .env idempotently.';

    public function handle(SafetyNetConfigurator $configurator, ConfigRepository $config, NymBundle $nymBundle): int
    {
        $backend = $this->resolveBackend();
        if ($backend === null) {
            return self::FAILURE;
        }
        if ($backend === 'kiji') {
            $this->error(self::KIJI_REMOVED);

            return self::INVALID;
        }
        if (! in_array($backend, ['nym', 'opf'], true)) {
            $this->error("unknown safety-net backend '{$backend}'; expected nym or opf");

            return self::INVALID;
        }

        if ($backend === 'nym') {
            $nymModelDir = $this->resolveNymModelDir($config, $nymBundle);
            if ($nymModelDir === false) {
                return self::FAILURE; // CB4: never write a .env doctor would reject
            }
            $pairs = SafetyNetConfigurator::pairsFor('nym', nymModelDir: $nymModelDir);
        } else {
            $pairs = SafetyNetConfigurator::pairsFor(
                $backend,
                $this->stringOption('opf-command'),
                $this->stringOption('opf-checkpoint'),
            );
        }

        if ((bool) $this->option('print')) {
            $this->line($configurator->preview($pairs));

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $result = null;

        if ($this->progressEnabled()) {
            // No network fetch here — this step only wires .env. The spinner
            // reports the setup honestly and surfaces DONE/FAIL without
            // swallowing the guidance lines below.
            $this->components->task(
                "Wiring safety-net backend ({$backend})",
                function () use ($configurator, $pairs, $force, &$result): bool {
                    $result = $configurator->apply($pairs, force: $force);

                    return true;
                },
            );
        } else {
            $result = $configurator->apply($pairs, force: $force);
        }

        if (! $result instanceof SafetyNetConfiguratorResult) {
            return self::FAILURE;
        }

        $result->status === SafetyNetConfigStatus::Unchanged
            ? $this->components->info("safety-net already wired ({$backend}); no change")
            : $this->components->info("safety-net wired ({$backend}) → {$result->envPath}");

        $this->components->warn('run `php artisan config:clear` so the new .env values take effect.');

        if ($backend === 'nym') {
            $this->components->warn(
                'the bundle checks ran as '.NymBundle::userLabel($nymBundle->effectiveUid()).'; gaze enforces them '
                .'for the user that runs it. Run `php artisan gaze:doctor` as the PHP-FPM pool / queue worker user.'
            );
            $this->components->warn(
                'each one-shot Gaze::clean() loads the Nym model (about 2 s per call); '
                .'use Gaze::daemon() for throughput (docs/how-to/daemon.md).'
            );

            return self::SUCCESS;
        }

        // CB4: doctor has no Laravel-side probe for the opf subprocess.
        $this->components->warn(
            'opf subprocess is local — install the opf binary + checkpoint yourself '
            .'(GAZE_OPENAI_FILTER_COMMAND / GAZE_OPENAI_FILTER_CHECKPOINT); '
            .'gaze:doctor cannot verify the opf subprocess.'
        );
        // The pinned release binaries are built without upstream's
        // `safety-net-openai` feature: with them every clean fails
        // `SafetyNetConfig` ("not compiled with feature safety-net-openai").
        $this->components->warn(
            'opf needs a gaze binary built with the safety-net-openai feature; '
            .'the pinned release binary is not. Point GAZE_BINARY at your own build.'
        );

        return self::SUCCESS;
    }

    /**
     * The `GAZE_NYM_MODEL_DIR` value to write: the `--nym-model-dir` option or
     * the prompt answer (made absolute), or null when neither is given and
     * the directory is already configured elsewhere (config / process env /
     * policy — the same lookup doctor uses). Returns false, after printing why
     * and how to fetch the bundle, when the bundle would fail doctor.
     */
    private function resolveNymModelDir(ConfigRepository $config, NymBundle $nymBundle): string|false|null
    {
        $given = $this->stringOption('nym-model-dir');

        /** @var array<string, mixed> $gazeConfig */
        $gazeConfig = (array) $config->get('gaze', []);
        $configuredDir = GazeOptions::fromConfig($gazeConfig)->nymModelDir;
        $policyPath = $this->configString($config, 'gaze.policy_path');
        $located = NymBundle::locate($configuredDir, $policyPath);

        if ($given === null && $this->input->isInteractive()) {
            $answer = $this->ask(
                'Nym bundle directory (from `gaze setup --safety-net nym`)',
                $located['dir'] ?? NymBundle::existingSetupDefault(),
            );
            $given = is_string($answer) && $answer !== '' ? $answer : null;
        }

        if ($given !== null) {
            $given = $this->absolute($given);
            $located = ['dir' => $given, 'source' => '--nym-model-dir'];
        }

        if ($located === null) {
            $this->error(
                'the nym backend needs the bundle directory: pass --nym-model-dir, set GAZE_NYM_MODEL_DIR, '
                .'or name it in the policy\'s [safety_net.nym] model_dir.'
            );
            $this->printNymSetupHint(null);

            return false;
        }

        $problems = $nymBundle->problems($located['dir']);
        if ($problems !== []) {
            $this->error("the Nym bundle at {$located['dir']} ({$located['source']}) would be refused by gaze; .env was not changed:");
            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }
            $this->line('  (checked as '.NymBundle::userLabel($nymBundle->effectiveUid()).'; gaze checks the user that runs it)');
            $this->printNymSetupHint($located['dir']);

            return false;
        }

        return $given;
    }

    private function printNymSetupHint(?string $modelDir): void
    {
        $binary = $this->laravel->make(BinaryResolver::class)->resolveOrNull()
            ?? $this->laravel->basePath('vendor/bin/gaze');

        // Own short lines so each command survives console width-wrapping.
        $this->line('Fetch the pinned bundle as the user PHP-FPM and your queue workers run as (replace www-data):');
        $this->line('  '.NymBundle::setupCommand($binary, $modelDir));
        if ($modelDir !== null && is_dir($modelDir)) {
            $this->line('Or hand the existing bundle to that user:');
            $this->line('  '.NymBundle::chownCommand($modelDir));
        }
        $this->line('Then re-run:');
        $this->line('  php artisan gaze:install:safety-net --safety-net=nym --nym-model-dir='.NymBundle::setupTarget($modelDir));
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = getcwd();

        return $cwd === false ? $path : rtrim($cwd, '/').'/'.$path;
    }

    private function resolveBackend(): ?string
    {
        $flag = $this->stringOption('safety-net');
        if ($flag !== null) {
            return $flag;
        }

        if (! $this->input->isInteractive()) {
            $this->error('non-interactive; pass --safety-net=nym or --safety-net=opf');

            return null;
        }

        $choice = $this->choice('Which safety-net backend?', [
            'nym' => 'Nym-small (compiled into the release binary)',
            'opf' => 'OpenAI privacy-filter (Tier 2, needs a safety-net-openai build)',
        ], 'nym');

        return is_string($choice) ? $choice : 'nym';
    }

    /**
     * Show a live spinner only on an interactive, decorated TTY; CI, piped
     * output and `--no-interaction` keep the plain immediate output.
     */
    private function progressEnabled(): bool
    {
        return $this->output->isDecorated() && $this->input->isInteractive();
    }
}
