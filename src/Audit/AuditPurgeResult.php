<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Audit;

final class AuditPurgeResult
{
    public function __construct(
        public readonly string $rawOutput,
        public readonly ?int $count = null,
    ) {}

    public function rawOutput(): string
    {
        return $this->rawOutput;
    }

    public function count(): ?int
    {
        return $this->count;
    }
}
