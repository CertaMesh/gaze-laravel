<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Process\Factory as ProcessFactory;
use Naoray\GazeLaravel\Audit\AuditService;
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
        ?string $auditDbPath = null,
    ): Gaze {
        $app = $this->applicationInstance();

        return new Gaze(
            resolver: new BinaryResolver(
                explicitPath: $explicitPath,
                vendorBinPath: $vendorBinPath,
            ),
            process: $app->make(ProcessFactory::class),
            timeoutSeconds: $timeoutSeconds,
            policyPath: $policyPath,
            maxBytes: $maxBytes,
            sessionTtlSeconds: $sessionTtlSeconds,
            auditDbPath: $auditDbPath,
            container: $app,
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

            public function audit(?string $auditDbPath = null): AuditService
            {
                throw new \LogicException(
                    'Scripted Gaze stub: audit() is not implemented for this test fixture. Use Gaze::fake() if your test needs to exercise audit verbs.'
                );
            }
        };

        $this->applicationInstance()->instance(Gaze::class, $stub);
    }

    public function bindCountingGaze(
        GazeSession $clean,
        int $expectedCalls,
        ?string $expectedInput = null,
    ): void {
        $stub = new class($clean, $expectedInput) extends Gaze
        {
            public int $calls = 0;

            public function __construct(
                private readonly GazeSession $clean,
                private readonly ?string $expectedInput,
            ) {
                // Skip parent constructor — no process invocations occur.
            }

            public function clean(string $text): GazeSession
            {
                $this->calls++;

                if ($this->expectedInput !== null) {
                    expect($text)->toBe($this->expectedInput);
                }

                return $this->clean;
            }

            public function restore(GazeSession $session, string $text): string
            {
                return $text;
            }

            public function audit(?string $auditDbPath = null): AuditService
            {
                throw new \LogicException(
                    'Counting Gaze stub: audit() is not implemented for this test fixture. Use Gaze::fake() if your test needs to exercise audit verbs.'
                );
            }
        };

        $this->applicationInstance()->instance(Gaze::class, $stub);
        $this->beforeApplicationDestroyed(function () use ($stub, $expectedCalls): void {
            expect($stub->calls)->toBe($expectedCalls);
        });
    }

    public function bindAndReturnCleanSession(string $cleanText, string $blob, int $detections): GazeSession
    {
        return new GazeSession(
            cleanText: $cleanText,
            ciphertext: EncryptedBlob::wrap($blob),
            detections: $detections,
        );
    }

    private function applicationInstance(): Application
    {
        if (! $this->app instanceof Application) {
            throw new \LogicException('Test application is not initialized.');
        }

        return $this->app;
    }
}
