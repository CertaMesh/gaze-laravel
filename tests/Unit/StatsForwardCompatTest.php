<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('tolerates unknown stats keys on clean output', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => 'blob',
            'stats' => ['detections' => 1, 'tokens' => 7],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice');

    expect($session->detections)->toBe(1);
});
