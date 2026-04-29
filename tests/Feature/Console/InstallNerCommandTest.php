<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Console\InstallNerCommand;
use Naoray\GazeLaravel\Install\NerArtifactSet;
use Naoray\GazeLaravel\Install\NerFetcher;
use Naoray\GazeLaravel\Install\NerInstallStatus;
use Naoray\GazeLaravel\Install\NerInstaller;
use Naoray\GazeLaravel\Install\NerManifest;
use Naoray\GazeLaravel\Install\PolicyTomlPatcher;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Output\OutputInterface;

function gin_command_tester(NerFetcher $fetcher): CommandTester
{
    $installer = new NerInstaller(
        fetcher: $fetcher,
        patcher: new PolicyTomlPatcher,
        manifest: NerManifest::fromFile(__DIR__.'/../../../resources/ner/SHA256SUMS'),
    );
    app()->instance(NerInstaller::class, $installer);

    $command = app()->make(InstallNerCommand::class);
    $command->setLaravel(app());

    return new CommandTester($command);
}

it('gaze:install-ner exposes the patched flag surface', function () {
    $command = $this->app->make(InstallNerCommand::class);
    $definition = $command->getDefinition();

    foreach (['variant', 'dest', 'update-policy', 'force', 'check', 'dry-run', 'yes', 'no-progress', 'locale'] as $option) {
        expect($definition->hasOption($option))->toBeTrue("--{$option} should exist");
    }

    expect($definition->hasOption('model'))->toBeFalse();
    expect($definition->hasOption('unpinned'))->toBeFalse();
});

it('--variant=bogus exits 2', function () {
    $tester = gin_command_tester(new class implements NerFetcher {
        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void {}

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return false;
        }
    });

    $exit = $tester->execute(['--variant' => 'bogus', '--check' => true]);

    expect($exit)->toBe(2);
});

it('--check returns failure when artifacts do not verify', function () {
    $tester = gin_command_tester(new class implements NerFetcher {
        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void {}

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return false;
        }
    });

    $exit = $tester->execute(['--check' => true, '--dest' => sys_get_temp_dir().'/missing-gaze-ner']);

    expect($exit)->toBe(1);
    expect($tester->getDisplay())->toContain(NerInstallStatus::CheckFailed->value);
});

it('prints policy snippet after a successful install when policy is not updated', function () {
    $tester = gin_command_tester(new class implements NerFetcher {
        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            mkdir($stagingDir, 0755, true);
            foreach ($set->fileNames() as $name) {
                file_put_contents($stagingDir.'/'.$name, $name);
            }
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return false;
        }
    });
    $dest = sys_get_temp_dir().'/gaze-command-'.bin2hex(random_bytes(6));

    try {
        $exit = $tester->execute(['--dest' => $dest, '--yes' => true, '--locale' => 'de']);

        expect($exit)->toBe(0);
        expect($tester->getDisplay())->toContain('[ner]');
        expect($tester->getDisplay())->toContain('locale = "de"');
    } finally {
        if (is_dir($dest)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($dest);
        }
    }
});
