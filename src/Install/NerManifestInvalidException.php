<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

final class NerManifestInvalidException extends NerInstallException
{
    public function exitCode(): int
    {
        return 2;
    }
}
