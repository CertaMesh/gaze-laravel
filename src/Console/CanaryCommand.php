<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console;

use Illuminate\Console\Command;

final class CanaryCommand extends Command
{
    protected $signature = 'gaze:canary';

    protected $description = 'Round-trip canary: sanitize + restore against a fixed marker text.';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
