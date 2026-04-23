<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests;

use Illuminate\Process\Factory as ProcessFactory;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\GazeServiceProvider;
use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\RestoredText;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [GazeServiceProvider::class];
    }

    public function makeGaze(
        string $explicitPath = '/fake/ghostwriter',
        string $vendorBinPath = '/nonexistent',
        int $timeoutSeconds = 5,
        bool $failClosed = true,
    ): Gaze {
        return new Gaze(
            resolver: new BinaryResolver(
                explicitPath: $explicitPath,
                vendorBinPath: $vendorBinPath,
            ),
            process: $this->app->make(ProcessFactory::class),
            timeoutSeconds: $timeoutSeconds,
            failClosed: $failClosed,
        );
    }

    public function bindScriptedGaze(
        GazeSession $sanitize,
        ?RestoredText $restore = null,
    ): void {
        $stub = new class($sanitize, $restore) extends Gaze
        {
            public function __construct(
                private readonly GazeSession $sanitize,
                private readonly ?RestoredText $restore,
            ) {
                // Skip parent constructor — no process invocations occur.
            }

            public function sanitize(string $text, ?Context $context = null): GazeSession
            {
                return $this->sanitize;
            }

            public function restore(string $text, string $sessionBlob): RestoredText
            {
                return $this->restore ?? new RestoredText(text: $text, warnings: []);
            }
        };

        $this->app->instance(Gaze::class, $stub);
    }
}
