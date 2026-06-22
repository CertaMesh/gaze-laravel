<?php

declare(strict_types=1);

use CertaMesh\Gaze\Entry;
use CertaMesh\Gaze\GazeSession;
use Illuminate\Support\Facades\Process;

it('exposes entries from the gaze clean response JSON', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1, contact Email_1',
            'session_blob' => 'blob-bytes',
            'stats' => ['detections' => 2],
            'entries' => [
                [
                    'class' => 'PersonName',
                    'raw' => 'Alice',
                    'token' => 'Name_1',
                    'family' => 'counter',
                ],
                [
                    'class' => 'Email',
                    'raw' => 'alice@example.com',
                    'token' => 'Email_1',
                    'family' => 'counter',
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice, contact alice@example.com');

    expect($session)->toBeInstanceOf(GazeSession::class)
        ->and($session->entries)->toHaveCount(2)
        ->and($session->entries[0])->toBeInstanceOf(Entry::class)
        ->and($session->entries[0]->class)->toBe('PersonName')
        ->and($session->entries[0]->raw)->toBe('Alice')
        ->and($session->entries[0]->token)->toBe('Name_1')
        ->and($session->entries[0]->family)->toBe('counter')
        ->and($session->entries[1]->class)->toBe('Email')
        ->and($session->entries[1]->token)->toBe('Email_1');
});

it('defaults entries to an empty array when the field is absent', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello world',
            'session_blob' => 'blob-bytes',
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->clean('Hello world');

    expect($session->entries)->toBe([]);
});

it('defaults entries to an empty array when the field is null', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello world',
            'session_blob' => 'blob-bytes',
            'stats' => ['detections' => 0],
            'entries' => null,
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->clean('Hello world');

    expect($session->entries)->toBe([]);
});

it('ignores non-array elements inside the entries list', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Email_1',
            'session_blob' => 'blob-bytes',
            'stats' => ['detections' => 1],
            'entries' => [
                'this-is-not-an-object',
                ['class' => 'Email', 'raw' => 'a@b.com', 'token' => 'Email_1'],
                42,
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->clean('Hello a@b.com');

    expect($session->entries)->toHaveCount(1)
        ->and($session->entries[0]->class)->toBe('Email')
        ->and($session->entries[0]->family)->toBeNull();
});
