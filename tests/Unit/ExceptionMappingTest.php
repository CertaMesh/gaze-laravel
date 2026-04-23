<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Exceptions\GazeBlobExpiredException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidBlobVersionException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidEncodingException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidSignatureException;
use Naoray\GazeLaravel\Exceptions\GazeIoException;
use Naoray\GazeLaravel\Exceptions\GazePipelineException;
use Naoray\GazeLaravel\Exceptions\GazePolicyConfigException;
use Naoray\GazeLaravel\Exceptions\GazePolicyOpenException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;

it('maps variants to their dedicated exception classes', function (string $error, string $class) {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: json_encode([
                'error' => $error,
                'exit' => match ($error) {
                    'PolicyConfig' => 2,
                    'Io', 'PolicyOpen' => 4,
                    default => 3,
                },
            ], JSON_THROW_ON_ERROR),
            exitCode: match ($error) {
                'PolicyConfig' => 2,
                'Io', 'PolicyOpen' => 4,
                default => 3,
            },
        ),
    ]);

    expect(fn () => $this->makeGaze()->restore(
        $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1),
        'Hello Name_1',
    ))->toThrow($class);
})->with([
    ['UnknownToken', GazeUnknownTokenException::class],
    ['InvalidSignature', GazeInvalidSignatureException::class],
    ['InvalidBlobVersion', GazeInvalidBlobVersionException::class],
    ['BlobExpired', GazeBlobExpiredException::class],
    ['Pipeline', GazePipelineException::class],
    ['PolicyConfig', GazePolicyConfigException::class],
    ['Io', GazeIoException::class],
    ['PolicyOpen', GazePolicyOpenException::class],
]);

it('marks blob-expired variants as requiring a fresh clean', function () {
    $exception = new GazeBlobExpiredException('expired', 3, hash('sha256', ''));

    expect($exception->requiresFreshClean())->toBeTrue();
});

it('marks invalid-blob-version variants as requiring a fresh clean', function () {
    $exception = new GazeInvalidBlobVersionException('expired', 3, hash('sha256', ''));

    expect($exception->requiresFreshClean())->toBeTrue();
});

it('throws invalid encoding before starting a subprocess', function () {
    Process::fake();

    $this->makeGaze()->clean("\xFF");
})->throws(GazeInvalidEncodingException::class);
