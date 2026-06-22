<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Exceptions\GazeBinaryMissingException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Process\Factory as ProcessFactory;

final class CheckCommand extends Command
{
    protected $signature = 'gaze:check';

    protected $description = 'Verify the gaze binary resolves and is runnable.';

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
            $this->line('gaze --version exited non-zero');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('version', trim($result->output()));

        [$encrypterLabel, $encrypterOk] = $this->resolveEncrypterLabel();
        $this->components->twoColumnDetail('encrypter', $encrypterLabel);

        if (! $encrypterOk) {
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('status', '<fg=green>OK</>');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveEncrypterLabel(): array
    {
        try {
            $this->laravel->make('gaze.encrypter');
        } catch (\Throwable $e) {
            $this->line($e->getMessage());

            return ['<fg=red>invalid</>', false];
        }

        /** @var Repository $config */
        $config = $this->laravel->make('config');
        $raw = $config->get('gaze.blob_encryption_key');

        $label = ($raw === null || $raw === '')
            ? 'gaze.encrypter (APP_KEY fallback)'
            : 'gaze.encrypter (dedicated key)';

        return [$label, true];
    }
}
