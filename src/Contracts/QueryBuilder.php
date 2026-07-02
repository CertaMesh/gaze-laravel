<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Contracts;

/**
 * Fluent builder contract for `gaze audit query`. Rows follow the upstream
 * TSV contract: each row is a list of column values.
 */
interface QueryBuilder
{
    /**
     * Restrict the query to restore-telemetry rows. Fluent.
     */
    public function onlyRestoreEvents(): self;

    /**
     * Run the query and return all matching rows.
     *
     * @return list<list<string>>
     */
    public function execute(): array;
}
