<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

final readonly class RestoredText
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $text,
        public array $warnings,
    ) {}
}
