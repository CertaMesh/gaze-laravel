<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\RestoredText;
use Naoray\GazeLaravel\Testing\FakeGaze;

it('satisfies real Gaze type hints', function () {
    $fake = new FakeGaze();
    $this->app->instance(Gaze::class, $fake);

    expect($this->app->make(Gaze::class))->toBeInstanceOf(Gaze::class)
        ->and($this->app->make(Gaze::class))->toBe($fake);
});

it('swaps the customer name and records the call by default', function () {
    $fake = new FakeGaze();

    $session = $fake->sanitize('Hello Alice', new Context(customerName: 'Alice'));

    expect($session->cleanText)->toBe('Hello <CUSTOMER_NAME>')
        ->and($session->placeholders)->toBe(['<CUSTOMER_NAME>'])
        ->and($fake->sanitizeCalls())->toHaveCount(1)
        ->and($fake->sanitizeCalls()[0]['text'])->toBe('Hello Alice');
});

it('round-trips by default', function () {
    $fake = new FakeGaze();

    $session = $fake->sanitize('Hello Alice', new Context(customerName: 'Alice'));
    $restored = $fake->restore($session->cleanText, $session->sessionBlob);

    expect($restored->text)->toBe('Hello Alice')
        ->and($fake->restoreCalls())->toHaveCount(1);
});

it('invokes custom handlers when provided', function () {
    $fake = new FakeGaze(
        sanitizeHandler: fn (string $text, ?Context $context) => new GazeSession(
            cleanText: '[CLEAN]',
            sessionBlob: 'b',
            placeholders: [],
            warnings: ['w'],
        ),
        restoreHandler: fn (string $text, string $blob) => new RestoredText(
            text: '[RESTORED]',
            warnings: [],
        ),
    );

    expect($fake->sanitize('anything')->cleanText)->toBe('[CLEAN]')
        ->and($fake->restore('x', 'y')->text)->toBe('[RESTORED]');
});
