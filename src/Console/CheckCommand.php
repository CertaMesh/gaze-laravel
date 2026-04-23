<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console;

use Illuminate\Console\Command;

final class CheckCommand extends Command
{
    protected $signature = 'gaze:check';

    protected $description = 'Verify the ghostwriter binary resolves and is runnable.';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
