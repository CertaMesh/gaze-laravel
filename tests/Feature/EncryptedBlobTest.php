<?php

declare(strict_types=1);

use Illuminate\Encryption\Encrypter;
use Naoray\GazeLaravel\EncryptedBlob;

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
