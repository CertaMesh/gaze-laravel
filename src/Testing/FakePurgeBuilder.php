<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Contracts\PurgeBuilder as PurgeBuilderContract;

/**
 * Test double for the purge builder. Implements `Contracts\PurgeBuilder`
 * directly and records executed purges on the owning `FakeAuditService`.
 * Mirrors the real builder's `before()`-required discipline.
 */
final class FakePurgeBuilder implements PurgeBuilderContract
{
    private ?string $before = null;

    public function __construct(
        private readonly FakeAuditService $audit,
    ) {}

    public function before(CarbonInterface|string $timestamp): self
    {
        $this->before = $timestamp instanceof CarbonInterface
            ? $timestamp->utc()->toIso8601ZuluString()
            : $timestamp;

        return $this;
    }

    public function dryRun(): AuditPurgeResult
    {
        return $this->record(dryRun: true);
    }

    public function execute(): AuditPurgeResult
    {
        return $this->record(dryRun: false);
    }

    private function record(bool $dryRun): AuditPurgeResult
    {
        if ($this->before === null) {
            throw new \LogicException('PurgeBuilder::before() must be called before dryRun()/execute().');
        }

        return $this->audit->recordPurgeCall($this->before, $dryRun);
    }
}
