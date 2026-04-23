<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;
use Naoray\GazeLaravel\Gaze;

final class DoctorCommand extends Command
{
    protected $signature = 'gaze:doctor {--deep : Run a clean/restore smoke test}';

    protected $description = 'Verify binary, policy, encrypter, and optional round-trip readiness.';

    public function handle(BinaryResolver $resolver, ProcessFactory $process, ConfigRepository $config, Gaze $gaze): int
    {
        try {
            $binary = $resolver->resolve();
        } catch (GazeBinaryMissingException $e) {
            $this->components->twoColumnDetail('binary', '<fg=red>missing</>');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('binary', $binary);

        $version = $process->newPendingProcess()->timeout(5)->run([$binary, '--version']);
        if (! $version->successful()) {
            $this->components->twoColumnDetail('version', '<fg=red>unknown</>');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('version', trim($version->output()));

        $policy = (string) $config->get('gaze.policy_path', '');
        $this->components->twoColumnDetail('policy', is_file($policy) ? $policy : '<fg=red>missing</>');
        if (! is_file($policy)) {
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        try {
            $this->laravel->make('gaze.encrypter');
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('encrypter', '<fg=red>invalid</>');
            $this->line($e->getMessage());
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('encrypter', '<fg=green>OK</>');
        $this->components->twoColumnDetail('max_bytes', (string) ($config->get('gaze.max_bytes') ?? 10485760));
        $this->components->twoColumnDetail('session_ttl_seconds', (string) ($config->get('gaze.session_ttl_seconds') ?? 86400));

        if ($this->option('deep')) {
            $session = $gaze->clean('doctor@example.com');
            $restored = $gaze->restore($session, $session->cleanText);

            if (! str_contains($restored, 'doctor@example.com')) {
                $this->components->twoColumnDetail('deep', '<fg=red>FAIL</>');
                $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('deep', '<fg=green>OK</>');
        }

        $this->components->twoColumnDetail('status', '<fg=green>OK</>');

        return self::SUCCESS;
    }
}
