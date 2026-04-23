<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Unit;

use Illuminate\Encryption\Encrypter;
use Naoray\GazeLaravel\EncryptedBlob;
use PHPUnit\Framework\TestCase;

final class EncryptedBlobTest extends TestCase
{
    public function test_round_trip_returns_original_plaintext(): void
    {
        $blob = new EncryptedBlob(new Encrypter(random_bytes(32), 'AES-256-CBC'));

        $ciphertext = $blob->wrap('secret-session-blob');

        self::assertNotSame('secret-session-blob', $ciphertext);
        self::assertSame('secret-session-blob', $blob->unwrap($ciphertext));
    }

    public function test_each_wrap_produces_distinct_ciphertext(): void
    {
        $blob = new EncryptedBlob(new Encrypter(random_bytes(32), 'AES-256-CBC'));

        self::assertNotSame($blob->wrap('x'), $blob->wrap('x'));
    }

    public function test_tamper_detection_via_laravel_aead(): void
    {
        $blob = new EncryptedBlob(new Encrypter(random_bytes(32), 'AES-256-CBC'));

        $ciphertext = $blob->wrap('a');
        $tampered = substr($ciphertext, 0, -4).'AAAA';

        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        $blob->unwrap($tampered);
    }
}
