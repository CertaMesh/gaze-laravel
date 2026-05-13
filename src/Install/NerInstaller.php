<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

use Naoray\GazeLaravel\Install\Lock\LockGuard;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class NerInstaller
{
    public function __construct(
        private readonly NerFetcher $fetcher,
        private readonly PolicyTomlPatcher $patcher,
        private readonly NerManifest $manifest,
        private readonly ?\Closure $diskFreeSpace = null,
    ) {}

    public function install(NerInstallerOptions $options, ?OutputInterface $output = null): NerInstallerResult
    {
        $output ??= new NullOutput;
        $set = $this->manifest->resolve($options->variant);
        $snippet = $this->policySnippet($options->dest, $options->locale);

        if ($options->check) {
            return new NerInstallerResult(
                status: $this->verifyInstalled($set, $options->dest)
                    ? NerInstallStatus::CheckPassed
                    : NerInstallStatus::CheckFailed,
                dest: $options->dest,
                policySnippet: $snippet,
            );
        }

        if ($options->dryRun) {
            return new NerInstallerResult(
                status: NerInstallStatus::DryRun,
                dest: $options->dest,
                policySnippet: $snippet,
            );
        }

        if (! $options->force && $this->fetcher->verify($set, $options->dest)) {
            $this->writeSha256Sums($options->dest);
            $policyWritten = $this->applyPolicyIfRequested($options);

            return new NerInstallerResult(
                status: NerInstallStatus::AlreadyInstalled,
                dest: $options->dest,
                policySnippet: $snippet,
                policyWritten: $policyWritten,
            );
        }

        $parent = dirname($options->dest);
        if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new NerTransportException("could not create NER destination parent: {$parent}");
        }
        $this->assertDiskSpace($parent, $set);

        $lock = LockGuard::acquire($parent.DIRECTORY_SEPARATOR.'.gaze-install-ner.lock');
        $staging = $parent.DIRECTORY_SEPARATOR.basename($options->dest).'.staging.'.bin2hex(random_bytes(4));
        $backup = null;
        $placedNewDest = false;

        try {
            $this->fetcher->fetch($set, $staging, $output);

            if (is_dir($options->dest)) {
                $backup = $options->dest.'.bak.'.bin2hex(random_bytes(4));
                if (! rename($options->dest, $backup)) {
                    throw new NerTransportException("could not move existing NER destination aside: {$options->dest}");
                }
            }

            if (! rename($staging, $options->dest)) {
                throw new NerTransportException("could not place NER destination: {$options->dest}");
            }
            $placedNewDest = true;

            if (file_put_contents($options->dest.DIRECTORY_SEPARATOR.'.gitignore', "*\n!.gitignore\n") === false) {
                throw new NerTransportException("could not write NER destination .gitignore: {$options->dest}");
            }
            $this->writeSha256Sums($options->dest);
            $policyWritten = $this->applyPolicyIfRequested($options);

            if ($backup !== null && is_dir($backup)) {
                $this->removeDirectory($backup);
            }

            return new NerInstallerResult(
                status: NerInstallStatus::Installed,
                dest: $options->dest,
                policySnippet: $snippet,
                policyWritten: $policyWritten,
            );
        } catch (\Throwable $e) {
            if ($placedNewDest && is_dir($options->dest)) {
                $this->removeDirectory($options->dest);
            }

            if ($backup !== null && is_dir($backup)) {
                rename($backup, $options->dest);
            }

            throw $e;
        } finally {
            if (is_dir($staging)) {
                $this->removeDirectory($staging);
            }

            $lock->release();
        }
    }

    private function verifyInstalled(NerArtifactSet $set, string $dir): bool
    {
        if (! $this->fetcher->verify($set, $dir)) {
            return false;
        }

        $sumsPath = $dir.DIRECTORY_SEPARATOR.'SHA256SUMS';
        if (! is_file($sumsPath)) {
            return false;
        }

        return file_get_contents($sumsPath) === $this->manifest->body();
    }

    private function writeSha256Sums(string $dest): void
    {
        $path = $dest.DIRECTORY_SEPARATOR.'SHA256SUMS';
        if (file_put_contents($path, $this->manifest->body()) === false) {
            throw new NerTransportException("could not write NER SHA256SUMS: {$path}");
        }
    }

    private function applyPolicyIfRequested(NerInstallerOptions $options): bool
    {
        if ($options->policyPath === null) {
            return false;
        }

        $this->patcher->apply($options->policyPath, $options->dest, $options->locale, $options->policyForce);

        return true;
    }

    private function policySnippet(string $dest, ?string $locale): string
    {
        return trim($this->patcher->buildAppended('', $dest, $locale));
    }

    private function assertDiskSpace(string $parent, NerArtifactSet $set): void
    {
        $free = $this->diskFreeSpace !== null
            ? ($this->diskFreeSpace)($parent)
            : @disk_free_space($parent);

        if ($free === false) {
            return;
        }

        $required = $set->totalSize() * 2;
        if ($free < $required) {
            throw new NerDiskSpaceException($required, (int) $free, $parent);
        }
    }

    private function removeDirectory(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($dir);
    }
}
