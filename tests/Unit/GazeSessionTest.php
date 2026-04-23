<?php

declare(strict_types=1);

use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\RestoredText;

it('exposes all GazeSession fields', function () {
    $session = new GazeSession(
        cleanText: 'Hello <CUSTOMER_NAME>',
        sessionBlob: 'blob-bytes',
        placeholders: ['<CUSTOMER_NAME>'],
        warnings: [],
    );

    expect($session->cleanText)->toBe('Hello <CUSTOMER_NAME>')
        ->and($session->sessionBlob)->toBe('blob-bytes')
        ->and($session->placeholders)->toBe(['<CUSTOMER_NAME>'])
        ->and($session->warnings)->toBe([]);
});

it('exposes RestoredText fields', function () {
    $restored = new RestoredText(text: 'Hello Alice', warnings: ['w1']);

    expect($restored->text)->toBe('Hello Alice')
        ->and($restored->warnings)->toBe(['w1']);
});
