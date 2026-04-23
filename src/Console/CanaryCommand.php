<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console;

use Illuminate\Console\Command;
use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Exceptions\GazeException;
use Naoray\GazeLaravel\Gaze;

final class CanaryCommand extends Command
{
    protected $signature = 'gaze:canary';

    protected $description = 'Round-trip canary: sanitize + restore against a fixed marker text.';

    private const CUSTOMER_NAME = 'Krishan Koenig';

    private const CUSTOMER_EMAIL = 'k@example.com';

    private const CUSTOMER_PHONE = '+353 1 234 5678';

    private const BODY = 'Hi, this is Krishan Koenig (k@example.com / +353 1 234 5678). Please cancel order ORD-CANARY-ZZ.';

    public function handle(Gaze $gaze): int
    {
        try {
            $session = $gaze->sanitize(
                self::BODY,
                new Context(
                    customerName: self::CUSTOMER_NAME,
                    customerEmail: self::CUSTOMER_EMAIL,
                    customerPhone: self::CUSTOMER_PHONE,
                ),
            );
        } catch (GazeException $e) {
            $this->components->twoColumnDetail('[1/3] sanitize', '<fg=red>FAIL</>');
            $this->line($e->getMessage());
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail(
            '[1/3] sanitize',
            '<fg=green>OK</> ('.count($session->placeholders).' placeholders)',
        );

        $leaks = [];
        foreach ([self::CUSTOMER_NAME, self::CUSTOMER_EMAIL, self::CUSTOMER_PHONE] as $needle) {
            if (str_contains($session->cleanText, $needle)) {
                $leaks[] = $needle;
            }
        }
        if ($leaks !== []) {
            $this->components->twoColumnDetail('[2/3] marker-absent', '<fg=red>FAIL</>');
            $this->line('PII strings leaked into clean text: '.count($leaks));
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('[2/3] marker-absent', '<fg=green>OK</>');

        try {
            $restored = $gaze->restore($session->cleanText, $session->sessionBlob);
        } catch (GazeException $e) {
            $this->components->twoColumnDetail('[3/3] restore+marker', '<fg=red>FAIL</>');
            $this->line($e->getMessage());
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        foreach ([self::CUSTOMER_NAME, self::CUSTOMER_EMAIL, self::CUSTOMER_PHONE] as $needle) {
            if (! str_contains($restored->text, $needle)) {
                $this->components->twoColumnDetail('[3/3] restore+marker', '<fg=red>FAIL</>');
                $this->line('missing after restore: '.substr($needle, 0, 4).'…');
                $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

                return self::FAILURE;
            }
        }
        $this->components->twoColumnDetail('[3/3] restore+marker', '<fg=green>OK</>');

        $this->components->twoColumnDetail('status', '<fg=green>PASS</>');

        return self::SUCCESS;
    }
}
