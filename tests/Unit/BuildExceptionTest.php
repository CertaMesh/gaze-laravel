<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Exceptions\GazeIoException;
use Naoray\GazeLaravel\Exceptions\GazeSigPipeException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;

it('maps stderr fixtures into typed exceptions', function () {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: json_encode(['error' => 'UnknownToken', 'exit' => 3], JSON_THROW_ON_ERROR),
            exitCode: 3,
        ),
    ]);

    $this->makeGaze()->restore($this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1), 'Hello Name_1');
})->throws(GazeUnknownTokenException::class);

it('treats empty-stderr sigpipe as a dedicated exception', function () {
    Log::spy();
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 141),
    ]);

    $this->makeGaze()->clean('Hello Alice');
})->throws(GazeSigPipeException::class);

it('treats non-empty-stderr sigpipe as a parsed variant', function () {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: json_encode(['error' => 'Io', 'exit' => 141], JSON_THROW_ON_ERROR),
            exitCode: 141,
        ),
    ]);

    $this->makeGaze()->clean('Hello Alice');
})->throws(GazeIoException::class);
