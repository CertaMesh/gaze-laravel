<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Naoray\GazeLaravel\Install\NerInstaller;
use Naoray\GazeLaravel\Install\NerInstallerOptions;
use Naoray\GazeLaravel\Install\NerInstallerResult;
use Naoray\GazeLaravel\Install\NerInstallException;
use Naoray\GazeLaravel\Install\NerInstallStatus;

final class InstallNerCommand extends Command
{
    protected $signature = 'gaze:install-ner
        {--variant=int8 : Quantization variant (int8 only in v0)}
        {--dest= : Override destination dir (default: storage/app/gaze-ner/davlan-mbert-ner-hrl-int8)}
        {--locale= : BCP47 locale hint to embed in [ner] block}
        {--update-policy : Write [ner] block to gaze.policy_path}
        {--force : Redownload and overwrite existing destination/policy}
        {--check : Verify existing install without downloading}
        {--dry-run : Preview without writing}
        {--yes : Non-interactive confirm}
        {--no-progress : Suppress progress output}';

    protected $description = 'Install the pinned ONNX NER model and optionally wire policy.toml.';

    public function handle(NerInstaller $installer, ConfigRepository $config, Application $app): int
    {
        try {
            $options = $this->buildOptions($config, $app);

            if (! $options->dryRun && ! $options->check && ! $this->confirmIfNeeded($options)) {
                $this->warn('aborted');

                return self::FAILURE;
            }

            $result = $installer->install($options, $this->output->getOutput());
            $this->renderResult($result);

            return match ($result->status) {
                NerInstallStatus::Installed,
                NerInstallStatus::AlreadyInstalled,
                NerInstallStatus::CheckPassed,
                NerInstallStatus::DryRun => self::SUCCESS,
                NerInstallStatus::CheckFailed => self::FAILURE,
            };
        } catch (NerInstallException $e) {
            $this->error($e->getMessage());

            return $e->exitCode();
        }
    }

    private function buildOptions(ConfigRepository $config, Application $app): NerInstallerOptions
    {
        $rawVariant = $this->option('variant');
        $variant = is_string($rawVariant) && $rawVariant !== '' ? $rawVariant : 'int8';
        $dest = $this->option('dest');
        if (! is_string($dest) || $dest === '') {
            $dest = $app->storagePath("app/gaze-ner/davlan-mbert-ner-hrl-{$variant}");
        }

        $policyPath = null;
        if ((bool) $this->option('update-policy')) {
            $configured = $config->get('gaze.policy_path');
            $policyPath = is_string($configured) && $configured !== ''
                ? $configured
                : $app->basePath('policy.toml');
        }

        $locale = $this->option('locale');

        return new NerInstallerOptions(
            variant: $variant,
            dest: $dest,
            force: (bool) $this->option('force'),
            check: (bool) $this->option('check'),
            dryRun: (bool) $this->option('dry-run'),
            locale: is_string($locale) && $locale !== '' ? $locale : null,
            policyPath: $policyPath,
            policyForce: (bool) $this->option('force'),
        );
    }

    private function confirmIfNeeded(NerInstallerOptions $options): bool
    {
        if ((bool) $this->option('yes')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('non-interactive; pass --yes to confirm');

            return false;
        }

        $this->line("about to download NER model into: {$options->dest}");
        $this->line('approx 184 MB for variant=int8.');

        return $this->confirm('proceed?', true);
    }

    private function renderResult(NerInstallerResult $result): void
    {
        match ($result->status) {
            NerInstallStatus::Installed => $this->info("installed: {$result->dest}"),
            NerInstallStatus::AlreadyInstalled => $this->info("already-installed: {$result->dest}"),
            NerInstallStatus::CheckPassed => $this->info("check-passed: {$result->dest}"),
            NerInstallStatus::CheckFailed => $this->error("check-failed: {$result->dest}"),
            NerInstallStatus::DryRun => $this->comment("dry-run: {$result->dest}"),
        };

        if (! $result->policyWritten && in_array($result->status, [
            NerInstallStatus::Installed,
            NerInstallStatus::AlreadyInstalled,
            NerInstallStatus::DryRun,
        ], true)) {
            $this->newLine();
            $this->line('paste this into your policy.toml or rerun with --update-policy:');
            $this->newLine();
            $this->line($result->policySnippet);
        }
    }
}
