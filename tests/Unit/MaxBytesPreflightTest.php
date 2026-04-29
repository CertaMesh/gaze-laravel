<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Exceptions\GazeInputTooLargeException;

it('throws before forking when input exceeds max bytes', function () {
    Process::fake();

    $this->makeGaze(maxBytes: 3)->clean('Hello');
})->throws(GazeInputTooLargeException::class);

it('throws before restore fork when wrapped payload exceeds max bytes', function () {
    Process::fake();

    $session = $this->bindAndReturnCleanSession('Hi', str_repeat('b', 20), 1);

    $this->makeGaze(maxBytes: 10)->restore($session, 'Hi');
})->throws(GazeInputTooLargeException::class);
