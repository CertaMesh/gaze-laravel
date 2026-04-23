<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Exceptions\GazeBlobExpiredException;
use Naoray\GazeLaravel\Exceptions\GazeException;
use Naoray\GazeLaravel\Exceptions\GazeRestoreFailedException;
use Naoray\GazeLaravel\Exceptions\GazeSanitizeFailedException;
use Naoray\GazeLaravel\Exceptions\GazeTimeoutException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;

it('maps generic failure to sanitize fallback', function () {
    $stderr = 'kaboom at line 42';

    Process::fake([
        '*' => Process::result(output: '', errorOutput: $stderr, exitCode: 7),
    ]);

    try {
        $this->makeGaze()->sanitize('hi');
        throw new \AssertionError('expected GazeSanitizeFailedException');
    } catch (GazeSanitizeFailedException $e) {
        expect($e->exitCode)->toBe(7)
            ->and($e->stderrHash)->toMatch('/^[a-f0-9]{64}$/')
            ->and($e->getMessage())->not->toContain($stderr);
    }
});

it('uses restore fallback for restore failure', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $this->makeGaze()->restore('x', 'blob');
})->throws(GazeRestoreFailedException::class);

it('maps UnknownToken stderr to GazeUnknownTokenException', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'UnknownToken("<CUSTOMER_NAME_99>")', exitCode: 2),
    ]);

    $this->makeGaze()->restore('x', 'blob');
})->throws(GazeUnknownTokenException::class);

it('maps BlobExpired stderr to GazeBlobExpiredException', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'BlobExpired: session too old', exitCode: 3),
    ]);

    $this->makeGaze()->restore('x', 'blob');
})->throws(GazeBlobExpiredException::class);

it('maps "timed out" stderr to GazeTimeoutException', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'Process timed out after 30s', exitCode: 124),
    ]);

    $this->makeGaze()->sanitize('x');
})->throws(GazeTimeoutException::class);

it('never includes stderr in the log line', function () {
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
        throw new \AssertionError('expected exception');
    } catch (GazeException) {
        // expected
    }

    expect($captured['message'])->toBe('gaze sanitize failed')
        ->and($captured['context'])->toHaveKey('stderr_sha256')
        ->and($captured['context'])->not->toHaveKey('stderr')
        ->and($captured['context']['stderr_sha256'])->toMatch('/^[a-f0-9]{64}$/')
        ->and(json_encode($captured, JSON_THROW_ON_ERROR))->not->toContain($stderr);
});
