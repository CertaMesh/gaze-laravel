<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Process\Factory as ProcessFactory;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\GazeServiceProvider;
use Naoray\GazeLaravel\GazeSession;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [GazeServiceProvider::class];
    }

    public function makeGaze(
        string $explicitPath = '/fake/gaze',
        string $vendorBinPath = '/nonexistent',
        int $timeoutSeconds = 5,
        string $policyPath = '/fake/policy.toml',
        ?int $maxBytes = null,
        ?int $sessionTtlSeconds = null,
    ): Gaze {
        return new Gaze(
            resolver: new BinaryResolver(
                explicitPath: $explicitPath,
                vendorBinPath: $vendorBinPath,
            ),
            process: $this->app->make(ProcessFactory::class),
            timeoutSeconds: $timeoutSeconds,
            policyPath: $policyPath,
            maxBytes: $maxBytes,
            sessionTtlSeconds: $sessionTtlSeconds,
        );
    }

    public function bindScriptedGaze(
        GazeSession $clean,
        ?string $restore = null,
    ): void {
        $stub = new class($clean, $restore) extends Gaze
        {
            public function __construct(
                private readonly GazeSession $clean,
                private readonly ?string $restore,
            ) {
                // Skip parent constructor — no process invocations occur.
            }

            public function clean(string $text): GazeSession
            {
                return $this->clean;
            }

            public function restore(GazeSession $session, string $text): string
            {
                return $this->restore ?? $text;
            }
        };

        $this->app->instance(Gaze::class, $stub);
    }

    public function bindAndReturnCleanSession(string $cleanText, string $blob, int $detections): GazeSession
    {
        return new GazeSession(
            cleanText: $cleanText,
            ciphertext: EncryptedBlob::wrap($blob),
            detections: $detections,
        );
    }
}
