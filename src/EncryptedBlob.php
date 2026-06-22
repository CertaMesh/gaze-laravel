<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;

final readonly class EncryptedBlob
{
    private function __construct(private string $ciphertext) {}

    public static function wrap(string $plaintextBlob): self
    {
        return new self(self::encrypter()->encryptString($plaintextBlob));
    }

    public static function fromCiphertext(string $ciphertext): self
    {
        return new self($ciphertext);
    }

    public function ciphertext(): string
    {
        return $this->ciphertext;
    }

    public function decryptedBlob(): string
    {
        return self::encrypter()->decryptString($this->ciphertext);
    }

    /**
     * @return EncrypterContract&StringEncrypter
     */
    private static function encrypter(): EncrypterContract
    {
        /** @var EncrypterContract&StringEncrypter $encrypter */
        $encrypter = app()->bound('gaze.encrypter')
            ? app('gaze.encrypter')
            : app('encrypter');

        return $encrypter;
    }
}
