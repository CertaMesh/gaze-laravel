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
 *     the command prints that exact command instead. gaze checks the bundle
 *     owner against the user that runs it, so `--runtime-user` names that user
 *     when the installer runs as someone else (the deploy user). A 0700
 *     bundle owned by that user is closed to the installer: only the
 *     directory itself is checked then, and the rest is left to
 *     `gaze:doctor` run as that user.
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
        {--runtime-user= : User that runs gaze (PHP-FPM pool / queue worker), as a name or uid; the bundle owner is checked against it (nym backend only)}
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

        $runtimeUser = null;
        $nym = null;
        if ($backend === 'nym') {
            $runtimeUser = $this->resolveRuntimeUser();
            if ($runtimeUser === false) {
                return self::INVALID;
            }
            if ($runtimeUser !== null) {
                $nymBundle = $nymBundle->forUid($runtimeUser['uid']);
            }

            $nym = $this->resolveNymModelDir($config, $nymBundle, $runtimeUser['name'] ?? null);
            if ($nym === false) {
                return self::FAILURE; // CB4: never write a .env doctor would reject
            }
            $pairs = SafetyNetConfigurator::pairsFor('nym', nymModelDir: $nym['write']);
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

        if ($nym !== null) {
            $this->warnNymFollowUp($nymBundle, $nym, $runtimeUser['name'] ?? null);
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
     * The `GAZE_NYM_MODEL_DIR` value to write (`write`): the `--nym-model-dir`
     * option or the prompt answer (made absolute), or null when neither is
     * given and the directory is already configured elsewhere (config /
     * process env / policy — the same lookup doctor uses). `dir` is the
     * directory that was checked; `unread` is true when this process could
     * not read it, so only the directory itself was checked. Returns false,
     * after printing why and how to fetch the bundle, when the bundle would
     * fail doctor.
     *
     * @return array{write: ?string, dir: string, unread: bool}|false
     */
    private function resolveNymModelDir(ConfigRepository $config, NymBundle $nymBundle, ?string $runtimeUser): array|false
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
                ($located['dir'] ?? '') !== '' ? $located['dir'] : NymBundle::existingSetupDefault(),
            );
            $given = is_string($answer) && $answer !== '' ? $answer : null;
        }

        if ($given !== null) {
            $given = $this->absolute($given);
            $located = ['dir' => $given, 'source' => '--nym-model-dir'];
        }

        if ($located !== null && $located['dir'] === '') {
            // A set-but-empty GAZE_NYM_MODEL_DIR wins over the policy
            // upstream, so a policy bundle would not save this wiring.
            $this->error(NymBundle::EMPTY_ENV.' .env was not changed.');
            $this->line('Or pass --nym-model-dir=<bundle dir>, which writes the directory into that line.');

            return false;
        }

        if ($located === null) {
            $this->error(
                'the nym backend needs the bundle directory: pass --nym-model-dir, set GAZE_NYM_MODEL_DIR, '
                .'or name it in the policy\'s [safety_net.nym] model_dir.'
            );
            $this->printNymSetupHint(null, $runtimeUser);

            return false;
        }

        $report = $nymBundle->inspect($located['dir']);
        if ($report['problems'] !== []) {
            $this->error("the Nym bundle at {$located['dir']} ({$located['source']}) would be refused by gaze; .env was not changed:");
            foreach ($report['problems'] as $problem) {
                $this->line("  - {$problem}");
            }
            if ($report['unread']) {
                $this->line('  - '.$nymBundle->unreadMessage());
            }
            $this->line('  (checked as '.NymBundle::userLabel($nymBundle->effectiveUid()).'; gaze checks the user that runs it)');
            $this->printNymSetupHint($located['dir'], $runtimeUser);

            return false;
        }

        return ['write' => $given, 'dir' => $located['dir'], 'unread' => $report['unread']];
    }

    /**
     * `--runtime-user` as a uid plus the name the hints use. Null when not
     * given; false, after printing why, when it names no user.
     *
     * @return array{uid: int, name: string}|false|null
     */
    private function resolveRuntimeUser(): array|false|null
    {
        $user = $this->stringOption('runtime-user');
        if ($user === null) {
            return null;
        }

        if (ctype_digit($user)) {
            $uid = (int) $user;
            $entry = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;

            return ['uid' => $uid, 'name' => is_array($entry) ? $entry['name'] : $user];
        }

        if (! function_exists('posix_getpwnam')) {
            $this->error("--runtime-user={$user} needs ext-posix to look up the user; pass the numeric uid instead.");

            return false;
        }

        $entry = posix_getpwnam($user);
        if (! is_array($entry)) {
            $this->error("--runtime-user={$user}: no such user on this host.");

            return false;
        }

        return ['uid' => $entry['uid'], 'name' => $user];
    }

    /**
     * After a nym wiring: say what the checks covered and how to confirm
     * them for the user that runs gaze.
     *
     * @param  array{write: ?string, dir: string, unread: bool}  $nym
     */
    private function warnNymFollowUp(NymBundle $nymBundle, array $nym, ?string $runtimeUser): void
    {
        $doctor = NymBundle::doctorCommand($runtimeUser ?? NymBundle::EXAMPLE_USER);

        if ($nym['unread']) {
            $this->components->warn(
                $nymBundle->unreadMessage().' here, so only the directory itself was checked. '
                .'Check its files as the runtime user: '.$doctor
            );

            return;
        }

        if (! $nymBundle->ownerChecked()) {
            $this->components->warn(
                'owner not checked (ext-posix missing). gaze refuses a bundle the runtime user does not own. '
                .'Check it as that user: '.$doctor
            );

            return;
        }

        if ($runtimeUser !== null) {
            $this->components->warn(
                'the bundle checks ran for '.NymBundle::userLabel($nymBundle->effectiveUid()).' (--runtime-user). '
                .'Confirm them as that user: '.$doctor
            );

            return;
        }

        $this->components->warn(
            'the bundle checks ran as '.NymBundle::userLabel($nymBundle->effectiveUid()).'; gaze enforces them '
            .'for the user that runs it. Run `php artisan gaze:doctor` as the PHP-FPM pool / queue worker user.'
        );
    }

    private function printNymSetupHint(?string $modelDir, ?string $runtimeUser): void
    {
        $binary = $this->laravel->make(BinaryResolver::class)->resolveOrNull()
            ?? $this->laravel->basePath('vendor/bin/gaze');
        $user = $runtimeUser ?? NymBundle::EXAMPLE_USER;
        $placeholder = $runtimeUser === null ? ' (replace '.NymBundle::EXAMPLE_USER.')' : '';

        // Own short lines so each command survives console width-wrapping.
        $this->line("Fetch the pinned bundle as the user PHP-FPM and your queue workers run as{$placeholder}:");
        $this->line('  '.NymBundle::setupCommand($binary, $modelDir, $user));
        $this->line('Then re-run as that user, or name it with --runtime-user. Whoever runs it must be able to write .env:');
        $this->line('  '.NymBundle::installCommand($modelDir, $user));
        if ($modelDir !== null && is_dir($modelDir)) {
            $this->line('Or hand the existing bundle to that user, then re-run with it:');
            $this->line('  '.NymBundle::chownCommand($modelDir, $user));
            $this->line('  '.NymBundle::installCommand($modelDir, $user, existing: true));
        }
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
