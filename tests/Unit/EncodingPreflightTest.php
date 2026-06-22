<?php

declare(strict_types=1);

use CertaMesh\Gaze\Exceptions\GazeInvalidEncodingException;
use Illuminate\Support\Facades\Process;

it('throws before forking on invalid utf-8 input', function () {
    Process::fake();

    $this->makeGaze()->clean("\xC3\x28");
})->throws(GazeInvalidEncodingException::class);
