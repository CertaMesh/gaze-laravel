<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Exceptions\GazeBlobExpiredException;
use Naoray\GazeLaravel\Exceptions\GazeException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidBlobVersionException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidEncodingException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidSignatureException;
use Naoray\GazeLaravel\Exceptions\GazeIoException;
use Naoray\GazeLaravel\Exceptions\GazePipelineException;
use Naoray\GazeLaravel\Exceptions\GazePolicyConfigException;
use Naoray\GazeLaravel\Exceptions\GazePolicyOpenException;
use Naoray\GazeLaravel\Exceptions\GazeSigPipeException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;
use Naoray\GazeLaravel\Queue\Contracts\RequiresFreshClean;

/** @param class-string<GazeException> $class */
it('maps variants to their dedicated exception classes', function (string $error, string $class) {
    if (! is_a($class, GazeException::class, true)) {
        throw new RuntimeException('expected GazeException subclass');
    }

    $stderr = json_encode([
        'error' => $error,
        'exit' => match ($error) {
            'PolicyConfig' => 2,
            'Io', 'PolicyOpen' => 4,
            'SigPipe' => 141,
            default => 3,
        },
    ], JSON_THROW_ON_ERROR).PHP_EOL;

    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: $stderr,
            exitCode: match ($error) {
                'PolicyConfig' => 2,
                'Io', 'PolicyOpen' => 4,
                'SigPipe' => 141,
                default => 3,
            },
        ),
    ]);

    try {
        $this->makeGaze()->restore(
            $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1),
            'Hello Name_1',
        );
    } catch (GazeException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->stderrHash)->toBe(hash('sha256', $stderr));

        return;
    }

    $this->fail("Expected {$class} to be thrown.");
})->with([
    ['UnknownToken', GazeUnknownTokenException::class],
    ['InvalidSignature', GazeInvalidSignatureException::class],
    ['InvalidBlobVersion', GazeInvalidBlobVersionException::class],
    ['BlobExpired', GazeBlobExpiredException::class],
    ['Pipeline', GazePipelineException::class],
    ['PolicyConfig', GazePolicyConfigException::class],
    ['Io', GazeIoException::class],
    ['SigPipe', GazeSigPipeException::class],
    ['PolicyOpen', GazePolicyOpenException::class],
]);

it('marks blob-expired variants as requiring a fresh clean', function () {
    $exception = new GazeBlobExpiredException('expired', 3, hash('sha256', ''));

    expect($exception)->toBeInstanceOf(RequiresFreshClean::class)
        ->and($exception->requiresFreshClean())->toBeTrue();
});

it('marks invalid-blob-version variants as requiring a fresh clean', function () {
    $exception = new GazeInvalidBlobVersionException('expired', 3, hash('sha256', ''));

    expect($exception)->toBeInstanceOf(RequiresFreshClean::class)
        ->and($exception->requiresFreshClean())->toBeTrue();
});

it('keeps exception log levels next to exception classes', function () {
    $io = new GazeIoException('io', 4, hash('sha256', ''));
    $unknown = new GazeUnknownTokenException('unknown', 3, hash('sha256', ''));
    $policy = new GazePolicyConfigException('policy', 2, hash('sha256', ''));

    expect($io->logLevel())->toBe('warning')
        ->and($unknown->logLevel())->toBe('notice')
        ->and($policy->logLevel())->toBe('notice');
});

it('throws invalid encoding before starting a subprocess', function () {
    Process::fake();

    $this->makeGaze()->clean("\xFF");
})->throws(GazeInvalidEncodingException::class);
