<?php

declare(strict_types=1);

use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\GazeSession;

it('does not serialize plaintext session blobs into the payload', function () {
    $session = new GazeSession(
        cleanText: 'Email_1',
        ciphertext: EncryptedBlob::wrap('alice@example.com'),
        detections: 1,
    );

    $serialized = serialize($session);

    expect($serialized)->not->toContain('alice@example.com')
        ->and(unserialize($serialized))
        ->toBeInstanceOf(GazeSession::class);
});
