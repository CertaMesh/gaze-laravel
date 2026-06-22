<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Facades\Gaze as GazeFacade;
use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\Install\LaravelNerFetcher;
use CertaMesh\Gaze\Install\NerFetcher;
use CertaMesh\Gaze\Install\NerInstaller;
use CertaMesh\Gaze\Install\NerManifest;

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

it('wires the facade to the bound singleton', function () {
    $direct = $this->app->make(Gaze::class);
    GazeFacade::setFacadeApplication($this->app);

    expect(GazeFacade::getFacadeRoot())->toBe($direct);
});

it('merges package config', function () {
    expect($this->app['config']->get('gaze.timeout_seconds'))->toBe(30)
        ->and($this->app['config']->get('gaze.policy_path'))->toBe(base_path('policy.toml'));
});

it('defaults gaze.binary to null so BinaryResolver auto-discovers vendor/bin', function () {
    // Regression for #11 / todo #208: the previous default of literal 'gaze'
    // caused BinaryResolver to short-circuit on explicitPath and never reach
    // the vendor/bin/gaze fallback where the Composer plugin (PR #12) deposits
    // the auto-installed binary. The default MUST be null.
    expect(getenv('GAZE_BINARY'))->toBeFalse();
    expect($this->app['config']->get('gaze.binary'))->toBeNull();
});

it('flows an explicit gaze.binary config through to BinaryResolver', function () {
    $explicit = '/usr/local/bin/custom-gaze';
    $this->app['config']->set('gaze.binary', $explicit);
    $this->app->forgetInstance(BinaryResolver::class);

    expect($this->app->make(BinaryResolver::class)->resolve())->toBe($explicit);
});

it('uses a distinct encrypter when a dedicated key is configured', function () {
    $this->app->forgetInstance('gaze.encrypter');

    $this->app['config']->set(
        'gaze.blob_encryption_key',
        'base64:'.base64_encode(random_bytes(32)),
    );

    $default = $this->app->make('encrypter');
    $dedicated = $this->app->make('gaze.encrypter');

    expect($default)->not->toBe($dedicated);
});

it('fails loudly on an invalid dedicated key', function () {
    $this->app->forgetInstance('gaze.encrypter');
    $this->app['config']->set('gaze.blob_encryption_key', 'not-base64-32-bytes');

    $this->app->make('gaze.encrypter');
})->throws(RuntimeException::class, 'base64-encoded 32 bytes');

it('binds NerInstaller as a singleton', function () {
    $this->app->instance(NerManifest::class, NerManifest::fromString(gl_nerChecksumFixture()));

    $a = $this->app->make(NerInstaller::class);
    $b = $this->app->make(NerInstaller::class);

    expect($a)->toBe($b);
});

it('binds LaravelNerFetcher as the v0 NerFetcher implementation', function () {
    $fetcher = $this->app->make(NerFetcher::class);

    expect($fetcher)->toBeInstanceOf(LaravelNerFetcher::class);
});

it('registers gaze:install-ner', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('gaze:install-ner');
});
