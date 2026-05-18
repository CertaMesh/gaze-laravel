<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Daemon\CleanResponse;

it('accepts a spec-shaped decoded payload', function () {
    $decoded = [
        'session_id' => 'agent-1',
        'clean_text' => 'Contact <Email_1> before the meeting.',
        'manifest' => [['kind' => 'Email', 'index' => 1]],
        'tokens' => ['Email_1' => 'alice@example.invalid'],
    ];

    $response = CleanResponse::fromArray($decoded);

    expect($response->sessionId)->toBe('agent-1');
    expect($response->cleanText)->toBe('Contact <Email_1> before the meeting.');
    expect($response->manifest)->toBe([['kind' => 'Email', 'index' => 1]]);
    expect($response->tokens)->toBe(['Email_1' => 'alice@example.invalid']);
    expect($response->raw)->toBe($decoded);
});

it('defaults missing optional keys to empty', function () {
    $response = CleanResponse::fromArray([
        'session_id' => 'x',
        'clean_text' => 'no tokens',
    ]);

    expect($response->manifest)->toBe([]);
    expect($response->tokens)->toBe([]);
});

it('preserves unknown forward-compat fields in raw', function () {
    $response = CleanResponse::fromArray([
        'session_id' => 'x',
        'clean_text' => '',
        'future_field' => 'forward-compat',
    ]);

    expect($response->raw)->toHaveKey('future_field', 'forward-compat');
});

it('is readonly', function () {
    $response = CleanResponse::fromArray(['session_id' => 'x', 'clean_text' => '']);

    expect(fn () => $response->sessionId = 'mutated')
        ->toThrow(Error::class);
});
