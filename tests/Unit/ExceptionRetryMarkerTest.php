<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;
use Naoray\GazeLaravel\Exceptions\GazeBlobExpiredException;
use Naoray\GazeLaravel\Exceptions\GazeRestoreFailedException;
use Naoray\GazeLaravel\Exceptions\GazeSanitizeFailedException;
use Naoray\GazeLaravel\Exceptions\GazeTimeoutException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;
use Naoray\GazeLaravel\Exceptions\TerminalGazeException;
use Naoray\GazeLaravel\Exceptions\TransientGazeException;

it('marks unknown-token as terminal', function () {
    $e = new GazeUnknownTokenException('x', 1, 'h');
    expect($e)->toBeInstanceOf(TerminalGazeException::class)
        ->and($e)->not->toBeInstanceOf(TransientGazeException::class);
});

it('marks blob-expired as terminal', function () {
    $e = new GazeBlobExpiredException('x', 1, 'h');
    expect($e)->toBeInstanceOf(TerminalGazeException::class);
});

it('marks binary-missing as terminal', function () {
    $e = new GazeBinaryMissingException('x');
    expect($e)->toBeInstanceOf(TerminalGazeException::class);
});

it('marks timeout as transient', function () {
    $e = new GazeTimeoutException('x', 1, 'h');
    expect($e)->toBeInstanceOf(TransientGazeException::class)
        ->and($e)->not->toBeInstanceOf(TerminalGazeException::class);
});

it('marks sanitize-failed as transient', function () {
    $e = new GazeSanitizeFailedException('x', 1, 'h');
    expect($e)->toBeInstanceOf(TransientGazeException::class);
});

it('marks restore-failed as transient', function () {
    $e = new GazeRestoreFailedException('x', 1, 'h');
    expect($e)->toBeInstanceOf(TransientGazeException::class);
});

it('lets callers branch retry policy via instanceof', function () {
    $cases = [
        [new GazeUnknownTokenException('x', 1, 'h'), 'terminal'],
        [new GazeBlobExpiredException('x', 1, 'h'), 'terminal'],
        [new GazeTimeoutException('x', 1, 'h'), 'transient'],
        [new GazeSanitizeFailedException('x', 1, 'h'), 'transient'],
    ];

    foreach ($cases as [$exception, $expected]) {
        $verdict = match (true) {
            $exception instanceof TerminalGazeException => 'terminal',
            $exception instanceof TransientGazeException => 'transient',
            default => 'unknown',
        };

        expect($verdict)->toBe($expected);
    }
});
