<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

use Composer\Script\Event;

final class BinaryInstaller
{
    public const PINNED_VERSION = '0.1.0';

    public static function postInstall(Event $event): void
    {
        // Implemented in step 10.
        unset($event);
    }
}
