<?php

declare(strict_types=1);

use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\GazeSession;

it('passes when the canary round-trip restores the known markers', function () {
    $clean = new GazeSession(
        cleanText: 'Hi, this is Ada Example (Email_1 / +353 1 234 5678). Please cancel order ORD-CANARY-ZZ.',
        ciphertext: EncryptedBlob::wrap(base64_encode(json_encode([
            'text' => 'Hi, this is Ada Example (k@example.com / +353 1 234 5678). Please cancel order ORD-CANARY-ZZ.',
        ], JSON_THROW_ON_ERROR))),
        detections: 1,
    );

    $this->bindScriptedGaze($clean, 'Hi, this is Ada Example (k@example.com / +353 1 234 5678). Please cancel order ORD-CANARY-ZZ.');

    $this->artisan('gaze:canary')
        ->assertExitCode(0)
        ->expectsOutputToContain('PASS');
});
