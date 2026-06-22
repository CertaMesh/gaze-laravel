<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

final readonly class GazeSession
{
    /**
     * @param  list<Entry>  $entries
     */
    public function __construct(
        public string $cleanText,
        public EncryptedBlob $ciphertext,
        public int $detections,
        public array $entries = [],
    ) {}
}
