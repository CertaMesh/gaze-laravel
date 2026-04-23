<?php

declare(strict_types=1);

use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\RestoredText;

it('passes when sanitize removes PII and restore brings it back', function () {
    $original = 'Hi, this is Krishan Koenig (k@example.com / +353 1 234 5678). Please cancel order ORD-CANARY-ZZ.';
    $clean = 'Hi, this is <CUSTOMER_NAME> (<CUSTOMER_EMAIL> / <CUSTOMER_PHONE>). Please cancel order ORD-CANARY-ZZ.';

    $this->bindScriptedGaze(
        sanitize: new GazeSession(
            cleanText: $clean,
            sessionBlob: 'blob',
            placeholders: ['<CUSTOMER_NAME>', '<CUSTOMER_EMAIL>', '<CUSTOMER_PHONE>'],
            warnings: [],
        ),
        restore: new RestoredText(text: $original, warnings: []),
    );

    $this->artisan('gaze:canary')
        ->assertExitCode(0)
        ->expectsOutputToContain('[1/3] sanitize')
        ->expectsOutputToContain('[2/3] marker-absent')
        ->expectsOutputToContain('[3/3] restore+marker')
        ->expectsOutputToContain('PASS');
});

it('fails when PII leaks into clean text', function () {
    $this->bindScriptedGaze(
        sanitize: new GazeSession(
            cleanText: 'Hi, Krishan Koenig is leaked',
            sessionBlob: 'blob',
            placeholders: [],
            warnings: [],
        ),
    );

    $this->artisan('gaze:canary')
        ->assertExitCode(1)
        ->expectsOutputToContain('leaked into clean text');
});

it('fails when restore drops PII', function () {
    $this->bindScriptedGaze(
        sanitize: new GazeSession(
            cleanText: 'Hi, this is <CUSTOMER_NAME> (<CUSTOMER_EMAIL> / <CUSTOMER_PHONE>).',
            sessionBlob: 'blob',
            placeholders: ['<CUSTOMER_NAME>', '<CUSTOMER_EMAIL>', '<CUSTOMER_PHONE>'],
            warnings: [],
        ),
        restore: new RestoredText(text: 'Hi, this is someone else.', warnings: []),
    );

    $this->artisan('gaze:canary')
        ->assertExitCode(1)
        ->expectsOutputToContain('missing after restore');
});
