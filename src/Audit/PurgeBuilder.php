<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Audit;

use Illuminate\Contracts\Process\ProcessResult;
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

    public function before(string $timestamp): self
    {
        $this->before = $timestamp;

        return $this;
    }

    public function dryRun(): ProcessResult
    {
        return $this->runPurge(dryRun: true);
    }

    public function execute(): ProcessResult
    {
        return $this->runPurge(dryRun: false);
    }

    protected function runPurge(bool $dryRun): ProcessResult
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

        return $this->gaze->runForAuditPurge($command);
    }
}
