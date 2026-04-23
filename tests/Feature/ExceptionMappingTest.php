<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Feature;

use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Exceptions\GazeBlobExpiredException;
use Naoray\GazeLaravel\Exceptions\GazeException;
use Naoray\GazeLaravel\Exceptions\GazeRestoreFailedException;
use Naoray\GazeLaravel\Exceptions\GazeSanitizeFailedException;
use Naoray\GazeLaravel\Exceptions\GazeTimeoutException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Tests\TestCase;

final class ExceptionMappingTest extends TestCase
{
    public function test_generic_failure_maps_to_sanitize_fallback(): void
    {
        $stderr = 'kaboom at line 42';

        Process::fake([
            '*' => Process::result(output: '', errorOutput: $stderr, exitCode: 7),
        ]);

        try {
            $this->makeGaze()->sanitize('hi');
            self::fail('expected GazeSanitizeFailedException');
        } catch (GazeSanitizeFailedException $e) {
            self::assertSame(7, $e->exitCode);
            self::assertSame(64, strlen($e->stderrHash));
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $e->stderrHash);
            self::assertStringNotContainsString($stderr, $e->getMessage());
        }
    }

    public function test_restore_failure_uses_restore_fallback(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
        ]);

        $this->expectException(GazeRestoreFailedException::class);
        $this->makeGaze()->restore('x', 'blob');
    }

    public function test_unknown_token_stderr_maps_to_unknown_token_exception(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'UnknownToken("<CUSTOMER_NAME_99>")', exitCode: 2),
        ]);

        $this->expectException(GazeUnknownTokenException::class);
        $this->makeGaze()->restore('x', 'blob');
    }

    public function test_blob_expired_stderr_maps_to_blob_expired_exception(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'BlobExpired: session too old', exitCode: 3),
        ]);

        $this->expectException(GazeBlobExpiredException::class);
        $this->makeGaze()->restore('x', 'blob');
    }

    public function test_timed_out_stderr_maps_to_timeout_exception(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'Process timed out after 30s', exitCode: 124),
        ]);

        $this->expectException(GazeTimeoutException::class);
        $this->makeGaze()->sanitize('x');
    }

    public function test_log_line_never_contains_stderr(): void
    {
        $stderr = 'SECRET: user email leaked here';
        Process::fake([
            '*' => Process::result(output: '', errorOutput: $stderr, exitCode: 1),
        ]);

        $captured = [];
        Log::shouldReceive('warning')->once()->andReturnUsing(
            function (string $message, array $context) use (&$captured): void {
                $captured = ['message' => $message, 'context' => $context];
            }
        );

        try {
            $this->makeGaze()->sanitize('x');
            self::fail('expected exception');
        } catch (GazeException) {
            // expected
        }

        self::assertSame('gaze sanitize failed', $captured['message']);
        self::assertArrayHasKey('stderr_sha256', $captured['context']);
        self::assertArrayNotHasKey('stderr', $captured['context']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $captured['context']['stderr_sha256']);
        self::assertStringNotContainsString($stderr, json_encode($captured, JSON_THROW_ON_ERROR));
    }

    private function makeGaze(): Gaze
    {
        return new Gaze(
            resolver: new BinaryResolver(
                explicitPath: '/fake/ghostwriter',
                vendorBinPath: '/nonexistent',
            ),
            process: $this->app->make(ProcessFactory::class),
            timeoutSeconds: 5,
        );
    }
}
