<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Audit;

use Carbon\CarbonInterface;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Gaze;

class PurgeBuilder
{
    private ?string $before = null;

    public function __construct(
        protected readonly Gaze $gaze,
        protected readonly BinaryResolver $resolver,
        protected readonly string $auditDbPath,
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
        return $this->runPurge(dryRun: true);
    }

    public function execute(): AuditPurgeResult
    {
        return $this->runPurge(dryRun: false);
    }

    protected function runPurge(bool $dryRun): AuditPurgeResult
    {
        if ($this->before === null) {
            throw new \LogicException('PurgeBuilder::before() must be called before dryRun()/execute().');
        }

        $command = [
            $this->resolver->resolve(),
            'audit',
            'purge',
            '--audit-db='.$this->auditDbPath,
            '--before='.$this->before,
        ];

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $result = $this->gaze->runForAuditPurge($command);
        $rawOutput = $result->output();

        return new AuditPurgeResult(
            rawOutput: $rawOutput,
            count: $this->parseRowCount($rawOutput),
        );
    }

    private function parseRowCount(string $stdout): ?int
    {
        // TODO: pin stdout once fixture audit-DB infrastructure exists:
        // tighten this contract. For now, parse opportunistically and keep
        // rawOutput available for callers when no count pattern is present.
        if (preg_match('/(\d+)\s+rows?/', trim($stdout), $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
