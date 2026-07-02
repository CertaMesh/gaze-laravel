<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\Testing\FakeDaemonManager;
use CertaMesh\Gaze\Testing\FakeGaze;

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

it('masks exactly like the one-shot FakeGaze clean path', function (string $text) {
    // Fake-parity guard: Gaze::fake() adopters must see the same masked
    // shape whether they go through the one-shot clean() or the daemon
    // session path — both delegate to the shared FakeTokenizer.
    $daemonCleanText = (new FakeDaemonManager)->clean('s1', $text)->cleanText;

    expect($daemonCleanText)->toBe((new FakeGaze)->clean($text)->cleanText);
})->with([
    'real email address' => ['Reach bob@example.invalid today'],
    'wrapped class token' => ['Ping <Location_2> now'],
    'wrapped custom token' => ['Order <Custom:order_id_9> shipped'],
    'bare lowercase token' => ['ref name_7 attached'],
    'name fallback' => ['Hello Alice'],
    'mixed classes' => ['Alice <Organization_3> bob@example.invalid custom:sku_2'],
]);

it('masks non-email classes, not only emails', function () {
    $response = (new FakeDaemonManager)->clean('s1', 'Ping <Location_2>, order <Custom:order_id_9>');

    expect($response->cleanText)->toBe('Ping <Name_1>, order <Custom:order_id_1>');
});

it('reports the same clean_text in the raw payload as in cleanText', function () {
    $response = (new FakeDaemonManager)->clean('s1', 'Reach bob@example.invalid');

    expect($response->raw['clean_text'])->toBe($response->cleanText);
});

it('memoises session() per id', function () {
    $manager = new FakeDaemonManager;

    expect($manager->session('x'))->toBe($manager->session('x'));
    expect($manager->session('x'))->not->toBe($manager->session('y'));
});
