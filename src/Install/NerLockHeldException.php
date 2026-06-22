<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

final class NerLockHeldException extends NerInstallException
{
    public function __construct(public readonly string $lockPath)
    {
        parent::__construct("another gaze:install:ner is running; lock held: {$lockPath}");
    }

    public function exitCode(): int
    {
        return 75;
    }
}
