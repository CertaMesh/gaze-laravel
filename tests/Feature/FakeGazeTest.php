<?php

declare(strict_types=1);

use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\Testing\FakeGaze;

it('satisfies the Gaze type hint', function () {
    $fake = new FakeGaze;
    $this->app->instance(Gaze::class, $fake);

    expect($this->app->make(Gaze::class))->toBeInstanceOf(Gaze::class)
        ->and($this->app->make(Gaze::class))->toBe($fake);
});

it('records clean and restore calls', function () {
    $fake = new FakeGaze;

    $session = $fake->clean('Hello Alice');
    $restored = $fake->restore($session, $session->cleanText);

    expect($fake->cleanCalls())->toHaveCount(1)
        ->and($fake->restoreCalls())->toHaveCount(1)
        ->and($restored)->toBe('Hello Alice');
});

it('matches the real token grammar for clean-text fixtures', function (string $input, string $expected) {
    $session = (new FakeGaze)->clean("Before {$input} after");

    expect($session->cleanText)->toBe("Before {$expected} after");
})->with([
    'wrapped email token' => ['<Email_1>', '<Name_1>'],
    'wrapped name token' => ['<Name_1>', '<Name_1>'],
    'wrapped location token' => ['<Location_1>', '<Name_1>'],
    'wrapped custom token' => ['<Custom:order_id_1>', '<Custom:order_id_1>'],
    'format-preserving email token' => ['email1@example.test', 'email1@example.test'],
    'bare lowercase token' => ['name_1', 'name_1'],
]);
