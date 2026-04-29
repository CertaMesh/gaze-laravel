<?php

declare(strict_types=1);

use Naoray\GazeLaravel\EncryptedBlob;

it('wraps and decrypts the blob', function () {
    $blob = EncryptedBlob::wrap('secret-session-blob');

    expect($blob->ciphertext())->not->toBe('secret-session-blob')
        ->and($blob->decryptedBlob())->toBe('secret-session-blob');
});

it('round-trips from existing ciphertext', function () {
    $blob = EncryptedBlob::wrap('secret-session-blob');
    $rehydrated = EncryptedBlob::fromCiphertext($blob->ciphertext());

    expect($rehydrated->ciphertext())->toBe($blob->ciphertext())
        ->and($rehydrated->decryptedBlob())->toBe('secret-session-blob');
});

it('stays a minimal value object', function () {
    expect(get_class_methods(EncryptedBlob::class))->not->toContain('__toString')
        ->and(get_class_methods(EncryptedBlob::class))->not->toContain('toArray');
});
