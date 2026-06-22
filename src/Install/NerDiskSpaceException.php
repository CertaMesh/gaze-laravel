<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

final class NerDiskSpaceException extends NerInstallException
{
    public function __construct(
        public readonly int $required,
        public readonly int $available,
        public readonly string $path,
    ) {
        parent::__construct(sprintf(
            'insufficient disk space at %s; required %d bytes, available %d',
            $path,
            $required,
            $available,
        ));
    }

    public function exitCode(): int
    {
        return 1;
    }
}
