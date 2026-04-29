<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

final class NerInstallerResult
{
    public function __construct(
        public readonly NerInstallStatus $status,
        public readonly string $dest,
        public readonly string $policySnippet,
        public readonly bool $policyWritten = false,
    ) {}
}
