<?php

declare(strict_types=1);

use CertaMesh\Gaze\EncryptedBlob;
use Illuminate\Encryption\Encrypter;

it('uses the host cipher for the dedicated encryption key path', function () {
    $this->app->forgetInstance('gaze.encrypter');
    $this->app['config']->set('app.cipher', 'AES-256-GCM');
    $this->app['config']->set(
        'gaze.blob_encryption_key',
        'base64:'.base64_encode(random_bytes(32)),
    );

    $encrypter = $this->app->make('gaze.encrypter');
    $reflection = new ReflectionClass($encrypter);
    $cipher = $reflection->getProperty('cipher')->getValue($encrypter);

    $blob = EncryptedBlob::wrap('secret-session-blob');

    expect($encrypter)->toBeInstanceOf(Encrypter::class)
        ->and($cipher)->toBe('AES-256-GCM')
        ->and($blob->decryptedBlob())->toBe('secret-session-blob');
});

it('round-trips a dedicated-key blob across separate config instances under AES-256-GCM', function () {
    $key = 'base64:'.base64_encode(random_bytes(32));
    $original = json_encode([
        'session_id' => 'abc123',
        'detections' => [
            ['label' => 'email', 'value' => 'alice@example.com'],
            ['label' => 'phone', 'value' => '+15551234567'],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->app->forgetInstance('gaze.encrypter');
    $this->app['config']->set('app.cipher', 'AES-256-GCM');
    $this->app['config']->set('gaze.blob_encryption_key', $key);

    $ciphertext = EncryptedBlob::wrap($original)->ciphertext();

    $this->refreshApplication();
    $this->app->forgetInstance('gaze.encrypter');
    $this->app['config']->set('app.cipher', 'AES-256-GCM');
    $this->app['config']->set('gaze.blob_encryption_key', $key);

    $restored = EncryptedBlob::fromCiphertext($ciphertext)->decryptedBlob();

    expect($restored)->toBe($original);
});
