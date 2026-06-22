<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Audit;

use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Gaze;

/**
 * Runs `gaze audit query` and parses the TSV output into rows.
 * Each row is a list of column values; the outer list is all matching rows.
 * Column count and order follow the upstream `gaze audit query` contract.
 */
class QueryBuilder
{
    /**
     * Default-initialised (not constructor-promoted) so subclasses that skip the
     * parent constructor — e.g. Testing\FakeQueryBuilder — still inherit a safe
     * value and the fluent toggle below.
     */
    protected bool $onlyRestoreEvents = false;

    public function __construct(
        protected readonly Gaze $gaze,
        protected readonly BinaryResolver $resolver,
        protected readonly string $auditDbPath,
    ) {}

    /**
     * Restrict the query to restore-telemetry rows by forwarding
     * `--restore-events` to `gaze audit query`. Fluent.
     */
    public function onlyRestoreEvents(): static
    {
        $this->onlyRestoreEvents = true;

        return $this;
    }

    /**
     * @return list<list<string>>
     */
    public function execute(): array
    {
        $command = [
            $this->resolver->resolve(),
            'audit',
            'query',
            '--audit-db='.$this->auditDbPath,
        ];

        if ($this->onlyRestoreEvents) {
            $command[] = '--restore-events';
        }

        $result = $this->gaze->runForAuditQuery($command);

        return $this->parseRows($result->output());
    }

    /**
     * @return list<list<string>>
     */
    private function parseRows(string $stdout): array
    {
        $rows = [];
        foreach (explode("\n", trim($stdout)) as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = explode("\t", $line);
        }

        return $rows;
    }
}
