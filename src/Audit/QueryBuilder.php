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
    public function __construct(
        protected readonly Gaze $gaze,
        protected readonly BinaryResolver $resolver,
        protected readonly string $auditDbPath,
    ) {}

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
