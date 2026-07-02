<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Audit;

/**
 * Result of a `gaze audit purge` run. The pinned upstream (0.11.x) prints a
 * JSON object `{"dry_run":bool,"matched":N,"deleted":N}`; `$matched` and
 * `$deleted` carry those fields verbatim and `$count` is the operative
 * number (`matched` for dry runs, `deleted` for real runs). All three are
 * null when stdout does not match a known shape — `$rawOutput` always
 * carries the raw stdout for inspection.
 */
final readonly class AuditPurgeResult
{
    public function __construct(
        public readonly string $rawOutput,
        public readonly ?int $count = null,
        public readonly ?int $matched = null,
        public readonly ?int $deleted = null,
    ) {}
}
