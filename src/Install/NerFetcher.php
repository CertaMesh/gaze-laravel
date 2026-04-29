<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

use Symfony\Component\Console\Output\OutputInterface;

interface NerFetcher
{
    /**
     * Stream and SHA-verify every artifact in $set into $stagingDir.
     *
     * Implementations throw a NerInstallException subtype on failure. The
     * caller owns final placement so install rollback stays centralized.
     */
    public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void;

    /**
     * Verify existing files in $dir against $set.
     */
    public function verify(NerArtifactSet $set, string $dir): bool;
}
