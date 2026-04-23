<?php

declare(strict_types=1);

use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\Facades\Gaze as GazeFacade;
use Naoray\GazeLaravel\Gaze;

it('resolves Gaze as a singleton', function () {
    $a = $this->app->make(Gaze::class);
    $b = $this->app->make(Gaze::class);

    expect($a)->toBeInstanceOf(Gaze::class)
        ->and($a)->toBe($b);
});

it('resolves BinaryResolver as a singleton', function () {
    $a = $this->app->make(BinaryResolver::class);
    $b = $this->app->make(BinaryResolver::class);

    expect($a)->toBe($b);
});

it('uses default encrypter when no dedicated key is set', function () {
    $blob = $this->app->make(EncryptedBlob::class);
    $plaintext = 'hello-gaze';

    expect($blob->unwrap($blob->wrap($plaintext)))->toBe($plaintext);
});

it('wires the facade to the bound singleton', function () {
    $direct = $this->app->make(Gaze::class);
    GazeFacade::setFacadeApplication($this->app);

    expect(GazeFacade::getFacadeRoot())->toBe($direct);
});

it('merges package config', function () {
    expect($this->app['config']->get('gaze.timeout_seconds'))->toBe(30)
        ->and($this->app['config']->get('gaze.fail_closed'))->toBeTrue();
});

it('uses a distinct encrypter when a dedicated key is configured', function () {
    $this->app->forgetInstance('gaze.encrypter');
    $this->app->forgetInstance(EncryptedBlob::class);

    $this->app['config']->set(
        'gaze.blob_encryption_key',
        base64_encode(random_bytes(32)),
    );

    $default = $this->app->make('encrypter');
    $dedicated = $this->app->make('gaze.encrypter');

    expect($default)->not->toBe($dedicated);

    $blob = $this->app->make(EncryptedBlob::class);
    expect($blob->unwrap($blob->wrap('x')))->toBe('x');
});

it('fails loudly on an invalid dedicated key', function () {
    $this->app->forgetInstance('gaze.encrypter');
    $this->app['config']->set('gaze.blob_encryption_key', 'not-base64-32-bytes');

    $this->app->make('gaze.encrypter');
})->throws(\RuntimeException::class, 'base64-encoded 32 bytes');
