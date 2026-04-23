<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\StringEncrypter;

final class EncryptedBlob
{
    /**
     * @param  Encrypter&StringEncrypter  $encrypter
     */
    public function __construct(private readonly Encrypter $encrypter) {}

    public function wrap(string $plaintextBlob): string
    {
        return $this->encrypter->encryptString($plaintextBlob);
    }

    public function unwrap(string $ciphertext): string
    {
        return $this->encrypter->decryptString($ciphertext);
    }
}
