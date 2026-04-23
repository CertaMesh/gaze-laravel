<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Process\Factory as ProcessFactory;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;

final class CheckCommand extends Command
{
    protected $signature = 'gaze:check';

    protected $description = 'Verify the ghostwriter binary resolves and is runnable.';

    public function handle(BinaryResolver $resolver, ProcessFactory $process): int
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

        $result = $process->newPendingProcess()->timeout(5)->run([$binary, '--version']);
        if (! $result->successful()) {
            $this->components->twoColumnDetail('version', '<fg=red>unknown</>');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');
            $this->line('ghostwriter --version exited non-zero');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('version', trim($result->output()));

        $encrypterLabel = $this->resolveEncrypterLabel();
        $this->components->twoColumnDetail('encrypter', $encrypterLabel);

        $this->components->twoColumnDetail('status', '<fg=green>OK</>');

        return self::SUCCESS;
    }

    private function resolveEncrypterLabel(): string
    {
        try {
            $this->laravel->make('gaze.encrypter');
        } catch (\Throwable $e) {
            $this->line($e->getMessage());

            return '<fg=red>invalid</>';
        }

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->laravel->make('config');
        $raw = $config->get('gaze.blob_encryption_key');

        return ($raw === null || $raw === '')
            ? 'gaze.encrypter (APP_KEY fallback)'
            : 'gaze.encrypter (dedicated key)';
    }
}
