<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

final class NerTransportException extends NerInstallException
{
    public function exitCode(): int
    {
        return 1;
    }
}
