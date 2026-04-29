<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

final class NerInstallerOptions
{
    public function __construct(
        public readonly string $variant,
        public readonly string $dest,
        public readonly bool $force,
        public readonly bool $check,
        public readonly bool $dryRun,
        public readonly ?string $locale,
        public readonly ?string $policyPath,
        public readonly bool $policyForce,
    ) {}
}
