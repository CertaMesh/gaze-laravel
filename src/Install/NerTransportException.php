<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

final class NerTransportException extends NerInstallException
{
    public function exitCode(): int
    {
        return 1;
    }
}
