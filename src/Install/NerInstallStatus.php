<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

enum NerInstallStatus: string
{
    case Installed = 'installed';
    case AlreadyInstalled = 'already-installed';
    case CheckPassed = 'check-passed';
    case CheckFailed = 'check-failed';
    case DryRun = 'dry-run';
}
