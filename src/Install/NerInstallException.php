<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

abstract class NerInstallException extends \RuntimeException
{
    abstract public function exitCode(): int;
}
