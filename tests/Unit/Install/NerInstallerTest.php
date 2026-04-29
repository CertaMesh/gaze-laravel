<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Install\NerArtifactSet;
use Naoray\GazeLaravel\Install\NerFetcher;
use Naoray\GazeLaravel\Install\NerInstallStatus;
use Naoray\GazeLaravel\Install\NerInstaller;
use Naoray\GazeLaravel\Install\NerInstallerOptions;
use Naoray\GazeLaravel\Install\NerManifest;
use Naoray\GazeLaravel\Install\PolicyTomlPatcher;
use Symfony\Component\Console\Output\OutputInterface;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/gaze-installer-'.bin2hex(random_bytes(6));
    mkdir($this->tmp);
    $this->resources = __DIR__.'/../../../resources/ner';
});

afterEach(function () {
    if (! is_dir($this->tmp)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }

    @rmdir($this->tmp);
});

function gi_installer(object $fetcher, string $resources): NerInstaller
{
    return new NerInstaller(
        fetcher: $fetcher,
        patcher: new PolicyTomlPatcher,
        manifest: NerManifest::fromFile($resources.'/SHA256SUMS'),
    );
}

function gi_options(string $dest, array $overrides = []): NerInstallerOptions
{
    return new NerInstallerOptions(
        variant: $overrides['variant'] ?? 'int8',
        dest: $dest,
        force: $overrides['force'] ?? false,
        check: $overrides['check'] ?? false,
        dryRun: $overrides['dryRun'] ?? false,
        locale: $overrides['locale'] ?? null,
        policyPath: $overrides['policyPath'] ?? null,
        policyForce: $overrides['policyForce'] ?? false,
    );
}

it('returns check-passed or check-failed without fetching', function () {
    $fetcher = new class implements NerFetcher {
        public int $fetches = 0;
        public bool $verifyResult = true;

        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            $this->fetches++;
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return $this->verifyResult;
        }
    };
    $installer = gi_installer($fetcher, $this->resources);

    $passed = $installer->install(gi_options($this->tmp.'/dest', ['check' => true]));
    $fetcher->verifyResult = false;
    $failed = $installer->install(gi_options($this->tmp.'/dest', ['check' => true]));

    expect($passed->status)->toBe(NerInstallStatus::CheckPassed);
    expect($failed->status)->toBe(NerInstallStatus::CheckFailed);
    expect($fetcher->fetches)->toBe(0);
});

it('installs into a staging dir then places artifacts and gitignore at dest', function () {
    $fetcher = new class implements NerFetcher {
        public ?string $stagingDir = null;

        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            $this->stagingDir = $stagingDir;
            mkdir($stagingDir, 0755, true);
            foreach ($set->fileNames() as $name) {
                file_put_contents($stagingDir.'/'.$name, $name);
            }
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return is_file($dir.'/model.onnx');
        }
    };
    $dest = $this->tmp.'/dest';
    $installer = gi_installer($fetcher, $this->resources);

    $result = $installer->install(gi_options($dest));

    expect($result->status)->toBe(NerInstallStatus::Installed);
    expect($result->dest)->toBe($dest);
    expect(is_file($dest.'/model.onnx'))->toBeTrue();
    expect(file_get_contents($dest.'/.gitignore'))->toBe("*\n!.gitignore\n");
    expect($fetcher->stagingDir)->not->toBe($dest);
    expect(is_dir((string) $fetcher->stagingDir))->toBeFalse();
});

it('does not fetch again when destination already verifies', function () {
    $fetcher = new class implements NerFetcher {
        public int $fetches = 0;

        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            $this->fetches++;
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return true;
        }
    };
    $installer = gi_installer($fetcher, $this->resources);

    $result = $installer->install(gi_options($this->tmp.'/dest'));

    expect($result->status)->toBe(NerInstallStatus::AlreadyInstalled);
    expect($fetcher->fetches)->toBe(0);
});

it('returns dry-run without writing or fetching', function () {
    $fetcher = new class implements NerFetcher {
        public int $fetches = 0;

        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            $this->fetches++;
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return false;
        }
    };
    $dest = $this->tmp.'/dest';
    $installer = gi_installer($fetcher, $this->resources);

    $result = $installer->install(gi_options($dest, ['dryRun' => true, 'locale' => 'de']));

    expect($result->status)->toBe(NerInstallStatus::DryRun);
    expect($result->policySnippet)->toContain('[ner]');
    expect($result->policySnippet)->toContain('locale = "de"');
    expect(is_dir($dest))->toBeFalse();
    expect($fetcher->fetches)->toBe(0);
});
