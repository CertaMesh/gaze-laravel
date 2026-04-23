<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Context;

it('returns session from binary output', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello <CUSTOMER_NAME>',
            'session_blob' => 'blob-bytes',
            'metadata' => ['placeholders' => ['<CUSTOMER_NAME>']],
            'warnings' => [],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->sanitize('Hello Alice', new Context(customerName: 'Alice'));

    expect($session->cleanText)->toBe('Hello <CUSTOMER_NAME>')
        ->and($session->sessionBlob)->toBe('blob-bytes')
        ->and($session->placeholders)->toBe(['<CUSTOMER_NAME>'])
        ->and($session->warnings)->toBe([]);

    Process::assertRan(function ($process): bool {
        expect($process->command[1])->toBe('sanitize');
        $payload = json_decode($process->input ?? '', true, flags: JSON_THROW_ON_ERROR);
        expect($payload['text'])->toBe('Hello Alice')
            ->and($payload['context'])->toBe(['customer_name' => 'Alice']);

        return true;
    });
});

it('sanitizes without context', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'foo',
            'session_blob' => 'b',
            'metadata' => ['placeholders' => []],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->sanitize('foo');

    expect($session->cleanText)->toBe('foo')
        ->and($session->placeholders)->toBe([])
        ->and($session->warnings)->toBe([]);

    Process::assertRan(function ($process): bool {
        $payload = json_decode($process->input ?? '', true, flags: JSON_THROW_ON_ERROR);
        expect($payload)->not->toHaveKey('context');

        return true;
    });
});

it('restores text', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'restored_text' => 'Hello Alice',
            'warnings' => ['w'],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $restored = $this->makeGaze()->restore('Hello <CUSTOMER_NAME>', 'blob-bytes');

    expect($restored->text)->toBe('Hello Alice')
        ->and($restored->warnings)->toBe(['w']);

    Process::assertRan(function ($process): bool {
        expect($process->command[1])->toBe('restore');
        $payload = json_decode($process->input ?? '', true, flags: JSON_THROW_ON_ERROR);
        expect($payload['text'])->toBe('Hello <CUSTOMER_NAME>')
            ->and($payload['session_blob'])->toBe('blob-bytes');

        return true;
    });
});
