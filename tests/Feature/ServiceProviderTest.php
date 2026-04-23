<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Feature;

use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\Facades\Gaze as GazeFacade;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_gaze_is_resolvable_as_singleton(): void
    {
        $a = $this->app->make(Gaze::class);
        $b = $this->app->make(Gaze::class);

        self::assertInstanceOf(Gaze::class, $a);
        self::assertSame($a, $b);
    }

    public function test_binary_resolver_is_singleton(): void
    {
        $a = $this->app->make(BinaryResolver::class);
        $b = $this->app->make(BinaryResolver::class);

        self::assertSame($a, $b);
    }

    public function test_encrypted_blob_uses_default_encrypter_when_no_dedicated_key(): void
    {
        $blob = $this->app->make(EncryptedBlob::class);

        $plaintext = 'hello-gaze';
        self::assertSame($plaintext, $blob->unwrap($blob->wrap($plaintext)));
    }

    public function test_facade_resolves_to_bound_singleton(): void
    {
        $direct = $this->app->make(Gaze::class);
        GazeFacade::setFacadeApplication($this->app);
        $resolved = GazeFacade::getFacadeRoot();

        self::assertSame($direct, $resolved);
    }

    public function test_config_is_merged(): void
    {
        self::assertSame(30, $this->app['config']->get('gaze.timeout_seconds'));
        self::assertTrue($this->app['config']->get('gaze.fail_closed'));
    }

    public function test_dedicated_encryption_key_uses_distinct_encrypter(): void
    {
        $this->app->forgetInstance('gaze.encrypter');
        $this->app->forgetInstance(EncryptedBlob::class);

        $this->app['config']->set(
            'gaze.blob_encryption_key',
            base64_encode(random_bytes(32)),
        );

        /** @var \Illuminate\Contracts\Encryption\Encrypter $default */
        $default = $this->app->make('encrypter');
        /** @var \Illuminate\Contracts\Encryption\Encrypter $dedicated */
        $dedicated = $this->app->make('gaze.encrypter');

        self::assertNotSame($default, $dedicated);

        $blob = $this->app->make(EncryptedBlob::class);
        self::assertSame('x', $blob->unwrap($blob->wrap('x')));
    }

    public function test_invalid_dedicated_key_fails_loud(): void
    {
        $this->app->forgetInstance('gaze.encrypter');
        $this->app['config']->set('gaze.blob_encryption_key', 'not-base64-32-bytes');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('base64-encoded 32 bytes');
        $this->app->make('gaze.encrypter');
    }
}
