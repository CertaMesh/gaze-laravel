<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Audit;

final readonly class AuditPurgeResult
{
    public function __construct(
        public readonly string $rawOutput,
        public readonly ?int $count = null,
    ) {}
}
