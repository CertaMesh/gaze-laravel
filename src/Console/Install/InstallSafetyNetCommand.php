<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Install;

use CertaMesh\Gaze\Console\Concerns\BuildsBinaryArgv;
use CertaMesh\Gaze\Install\SafetyNetConfigStatus;
use CertaMesh\Gaze\Install\SafetyNetConfigurator;
use CertaMesh\Gaze\Install\SafetyNetConfiguratorResult;
use Illuminate\Console\Command;

/**
 * Wire a gaze safety-net backend into `.env` idempotently.
 *
 *   - `opf`  (Tier 2, openai-filter) — a LOCAL subprocess; we wire the optional
 *     command/checkpoint paths and warn that doctor cannot verify the subprocess.
 *
 * The `kiji` backend is gone: upstream removed Kiji DistilBERT in gaze 0.15.0,
 * so `--safety-net=kiji` fails before anything is written. Its replacement,
 * Nym, is not wired here yet (CertaMesh/gaze-laravel#157).
 */
final class InstallSafetyNetCommand extends Command
{
    use BuildsBinaryArgv;

    /** Shared with the `gaze:install` umbrella, which rejects kiji up front. */
    public const KIJI_REMOVED = 'the kiji safety-net backend was removed upstream in gaze 0.15.0. '
        .'Its replacement, nym, is not wired by the installer yet (tracked in CertaMesh/gaze-laravel#157); '
        .'set it up by hand per docs/how-to/safety-net.md. (opf needs a gaze binary built with the '
        .'safety-net-openai feature; the pinned release binary is not.)';

    protected $signature = 'gaze:install:safety-net
        {--safety-net= : Backend to wire non-interactively: opf}
        {--opf-command= : Path to the local opf subprocess binary (opf backend only)}
        {--opf-checkpoint= : opf model checkpoint directory (opf backend only)}
        {--force : Overwrite existing safety-net env keys}
        {--print : Print the env lines instead of writing .env}';

    protected $description = 'Wire a gaze safety-net backend (opf) into .env idempotently.';

    public function handle(SafetyNetConfigurator $configurator): int
    {
        $backend = $this->resolveBackend();
        if ($backend === null) {
            return self::FAILURE;
        }
        if ($backend === 'kiji') {
            $this->error(self::KIJI_REMOVED);

            return self::INVALID;
        }
        if ($backend !== 'opf') {
            $this->error("unknown safety-net backend '{$backend}'; expected opf");

            return self::INVALID;
        }

        $pairs = SafetyNetConfigurator::pairsFor(
            $backend,
            $this->stringOption('opf-command'),
            $this->stringOption('opf-checkpoint'),
        );

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

    private function resolveBackend(): ?string
    {
        $flag = $this->stringOption('safety-net');
        if ($flag !== null) {
            return $flag;
        }

        if (! $this->input->isInteractive()) {
            $this->error('non-interactive; pass --safety-net=opf');

            return null;
        }

        $choice = $this->choice('Which safety-net backend?', [
            'opf' => 'OpenAI privacy-filter (Tier 2)',
        ], 'opf');

        return is_string($choice) ? $choice : 'opf';
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
