<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use CertaMesh\Gaze\Contracts\QueryBuilder as QueryBuilderContract;

/**
 * Test double for the query builder. Implements `Contracts\QueryBuilder`
 * directly and returns the pre-seeded rows; `onlyRestoreEvents()` stays a
 * fluent no-op recorder so scripted rows are returned either way.
 */
final class FakeQueryBuilder implements QueryBuilderContract
{
    private bool $onlyRestoreEvents = false;

    /** @var list<list<string>> */
    private array $rows;

    /**
     * @param  list<list<string>>  $rows
     */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    public function onlyRestoreEvents(): self
    {
        $this->onlyRestoreEvents = true;

        return $this;
    }

    /**
     * Whether onlyRestoreEvents() was toggled — exposed so tests can assert
     * the restriction was requested even though the fake returns scripted rows.
     */
    public function wasRestrictedToRestoreEvents(): bool
    {
        return $this->onlyRestoreEvents;
    }

    /**
     * @return list<list<string>>
     */
    public function execute(): array
    {
        return $this->rows;
    }
}
