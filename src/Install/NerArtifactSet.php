<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

final class NerArtifactSet
{
    /**
     * @param  array<string, array{sha: string, size: int, source?: string, sourceName?: string}>  $files
     */
    public function __construct(
        public readonly string $urlBase,
        public readonly array $files,
    ) {}

    public function totalSize(): int
    {
        $sum = 0;

        foreach ($this->files as $entry) {
            $sum += $entry['size'];
        }

        return $sum;
    }

    /**
     * @return list<string>
     */
    public function fileNames(): array
    {
        return array_values(array_keys($this->files));
    }
}
