<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Testing;

use Naoray\GazeLaravel\Audit\QueryBuilder;

final class FakeQueryBuilder extends QueryBuilder
{
    /** @var list<list<string>> */
    private array $rows;

    /**
     * @param  list<list<string>>  $rows
     */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    /**
     * @return list<list<string>>
     */
    public function execute(): array
    {
        return $this->rows;
    }
}
