<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\EncryptedBlob;

it('decodes the clean response into a session', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => 'blob-bytes',
            'stats' => ['detections' => 1],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice');

    expect($session->cleanText)->toBe('Hello Name_1')
        ->and($session->detections)->toBe(1)
        ->and($session->ciphertext)->toBeInstanceOf(EncryptedBlob::class)
        ->and($session->ciphertext->decryptedBlob())->toBe('blob-bytes');
});

it('decodes restore responses from the text key only', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'text' => 'Hello Alice',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob-bytes', 1);

    expect($this->makeGaze()->restore($session, 'Hello Name_1'))->toBe('Hello Alice');
});
