<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console;

use Illuminate\Console\Command;
use Naoray\GazeLaravel\Exceptions\GazeException;
use Naoray\GazeLaravel\Gaze;

final class CanaryCommand extends Command
{
    protected $signature = 'gaze:canary';

    protected $description = 'Round-trip canary: clean + restore against a fixed marker text.';

    private const CUSTOMER_EMAIL = 'k@example.com';

    private const BODY = 'Hi, this is Ada Example (k@example.com / +353 1 234 5678). Please cancel order ORD-CANARY-ZZ.';

    public function handle(Gaze $gaze): int
    {
        try {
            $session = $gaze->clean(self::BODY);
        } catch (GazeException $e) {
            $this->components->twoColumnDetail('[1/3] clean', '<fg=red>FAIL</>');
            $this->line($e->getMessage());
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail(
            '[1/3] clean',
            '<fg=green>OK</> ('.$session->detections.' detections)',
        );

        if (str_contains($session->cleanText, self::CUSTOMER_EMAIL)) {
            $this->components->twoColumnDetail('[2/3] marker-absent', '<fg=red>FAIL</>');
            $this->line('email marker leaked into clean text');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('[2/3] marker-absent', '<fg=green>OK</>');

        try {
            $restored = $gaze->restore($session, $session->cleanText);
        } catch (GazeException $e) {
            $this->components->twoColumnDetail('[3/3] restore+marker', '<fg=red>FAIL</>');
            $this->line($e->getMessage());
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        if (! str_contains($restored, self::CUSTOMER_EMAIL)) {
            $this->components->twoColumnDetail('[3/3] restore+marker', '<fg=red>FAIL</>');
            $this->line('missing after restore: email');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('[3/3] restore+marker', '<fg=green>OK</>');

        $this->components->twoColumnDetail('status', '<fg=green>PASS</>');

        return self::SUCCESS;
    }
}
