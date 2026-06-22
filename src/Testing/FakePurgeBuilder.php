<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Audit\PurgeBuilder;

final class FakePurgeBuilder extends PurgeBuilder
{
    private ?string $before = null;

    public function __construct(
        private readonly FakeAuditService $audit,
    ) {
        // Deliberately skip parent constructor — fake never invokes process.
    }

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
