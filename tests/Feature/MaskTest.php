<?php

declare(strict_types=1);

use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\Entry;
use CertaMesh\Gaze\Facades\Gaze;
use CertaMesh\Gaze\GazeSession;
use Illuminate\Support\Facades\Process;

function fakeCleanWithEntries(): void
{
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1, contact Email_1',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 2],
            'entries' => [
                ['class' => 'PersonName', 'raw' => 'Alice', 'token' => 'Name_1', 'family' => 'counter'],
                ['class' => 'Email', 'raw' => 'alice@example.com', 'token' => 'Email_1', 'family' => 'counter'],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);
}

it('masks detected tokens with default [Class] labels', function () {
    fakeCleanWithEntries();

    $masked = $this->makeGaze(policyPath: '/tmp/policy.toml')
        ->mask('Hello Alice, contact alice@example.com');

    expect($masked)->toBe('Hello [PersonName], contact [Email]');
});

it('drops the detected token placeholders from the masked output', function () {
    fakeCleanWithEntries();

    $masked = $this->makeGaze(policyPath: '/tmp/policy.toml')
        ->mask('Hello Alice, contact alice@example.com');

    expect($masked)
        ->not->toContain('Name_1')
        ->not->toContain('Email_1');
});

it('applies a custom replacement callable receiving the Entry', function () {
    fakeCleanWithEntries();

    $masked = $this->makeGaze(policyPath: '/tmp/policy.toml')
        ->mask(
            'Hello Alice, contact alice@example.com',
            fn (Entry $entry): string => '***'.strtoupper($entry->class).'***',
        );

    expect($masked)->toBe('Hello ***PERSONNAME***, contact ***EMAIL***');
});

it('returns a plain string with no reversible session (one-way)', function () {
    fakeCleanWithEntries();

    $masked = $this->makeGaze(policyPath: '/tmp/policy.toml')
        ->mask('Hello Alice, contact alice@example.com');

    // mask() yields a bare string — there is no GazeSession / ciphertext to
    // restore from, unlike clean().
    expect($masked)->toBeString()
        ->and($masked)->not->toBeInstanceOf(GazeSession::class);
});

it('mirrors mask() through Gaze::fake() driven by the clean inventory', function () {
    $fake = Gaze::fake(cleanHandler: fn (string $text): GazeSession => new GazeSession(
        cleanText: 'Hi Name_1',
        ciphertext: EncryptedBlob::wrap('blob'),
        detections: 1,
        entries: [new Entry('PersonName', 'Bob', 'Name_1', 'counter')],
    ));

    $masked = Gaze::mask('Hi Bob');

    expect($masked)->toBe('Hi [PersonName]')
        ->and($fake->maskCalls())->toHaveCount(1)
        ->and($fake->maskCalls()[0]['text'])->toBe('Hi Bob');
});
