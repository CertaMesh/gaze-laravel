<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Contracts;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\Audit\AuditPurgeResult;

/**
 * Fluent builder contract for `gaze audit purge`. `before()` MUST be
 * called before `dryRun()`/`execute()`; implementations throw a
 * `\LogicException` otherwise.
 */
interface PurgeBuilder
{
    /**
     * Restrict the purge to rows older than the given timestamp
     * (Carbon instances are normalised to ISO8601 Zulu). Fluent.
     */
    public function before(CarbonInterface|string $timestamp): self;

    /**
     * Run the purge with `--dry-run` — reports what would be removed.
     */
    public function dryRun(): AuditPurgeResult;

    /**
     * Run the purge for real.
     */
    public function execute(): AuditPurgeResult;
}
