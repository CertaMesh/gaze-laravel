<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Naoray\GazeLaravel\EncryptedBlob;

it('round-trips plaintext unchanged', function () {
    $blob = new EncryptedBlob(new Encrypter(random_bytes(32), 'AES-256-CBC'));

    $ciphertext = $blob->wrap('secret-session-blob');

    expect($ciphertext)->not->toBe('secret-session-blob')
        ->and($blob->unwrap($ciphertext))->toBe('secret-session-blob');
});

it('produces distinct ciphertext each wrap', function () {
    $blob = new EncryptedBlob(new Encrypter(random_bytes(32), 'AES-256-CBC'));

    expect($blob->wrap('x'))->not->toBe($blob->wrap('x'));
});

it('detects tampering via Laravel AEAD envelope', function () {
    $blob = new EncryptedBlob(new Encrypter(random_bytes(32), 'AES-256-CBC'));

    $ciphertext = $blob->wrap('a');
    $tampered = substr($ciphertext, 0, -4).'AAAA';

    $blob->unwrap($tampered);
})->throws(DecryptException::class);
