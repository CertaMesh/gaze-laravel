<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

final readonly class GazeSession
{
    /**
     * @param  list<string>  $placeholders
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $cleanText,
        public string $sessionBlob,
        public array $placeholders,
        public array $warnings,
    ) {}
}
