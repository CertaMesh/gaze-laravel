<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

final class NerShaMismatchException extends NerInstallException
{
    public function __construct(
        public readonly string $fileName,
        public readonly string $expected,
        public readonly string $actual,
    ) {
        parent::__construct(sprintf(
            'sha256 mismatch for %s; expected %s, got %s',
            $fileName,
            $expected,
            $actual,
        ));
    }

    public function exitCode(): int
    {
        return 1;
    }
}
