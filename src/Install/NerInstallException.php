<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

abstract class NerInstallException extends \RuntimeException
{
    abstract public function exitCode(): int;
}
