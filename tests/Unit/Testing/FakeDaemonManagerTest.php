<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\Testing\FakeDaemonManager;

it('records every clean call with session id and text', function () {
    $manager = new FakeDaemonManager;

    $manager->clean('s1', 'hello');
    $manager->session('s2')->clean('world');

    expect($manager->calls())->toBe([
        ['session_id' => 's1', 'text' => 'hello'],
        ['session_id' => 's2', 'text' => 'world'],
    ]);
});

it('returns a CleanResponse with masked email when no handler provided', function () {
    $manager = new FakeDaemonManager;

    $response = $manager->clean('s1', 'Contact alice@example.invalid please');

    expect($response)->toBeInstanceOf(CleanResponse::class);
    expect($response->cleanText)->toContain('<Email_1>');
    expect($response->sessionId)->toBe('s1');
});

it('delegates to a custom handler when one is supplied', function () {
    $manager = new FakeDaemonManager(static function (string $sessionId, string $text): CleanResponse {
        return new CleanResponse(
            sessionId: $sessionId,
            cleanText: "CUSTOM:{$text}",
            manifest: [],
            tokens: [],
            raw: [],
        );
    });

    $response = $manager->clean('s1', 'hi');

    expect($response->cleanText)->toBe('CUSTOM:hi');
});

it('memoises session() per id', function () {
    $manager = new FakeDaemonManager;

    expect($manager->session('x'))->toBe($manager->session('x'));
    expect($manager->session('x'))->not->toBe($manager->session('y'));
});
