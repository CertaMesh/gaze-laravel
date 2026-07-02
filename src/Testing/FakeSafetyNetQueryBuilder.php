<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\Contracts\SafetyNetQueryBuilder as SafetyNetQueryBuilderContract;

/**
 * Test double for the safety-net query builder. Implements
 * `Contracts\SafetyNetQueryBuilder` directly and returns the pre-seeded
 * rows; every filter method stays a fluent no-op recorder exposed via
 * `appliedFilters()` keyed by upstream flag name. `execute()` records the
 * call on the owning `FakeAuditService` when one is attached.
 */
final class FakeSafetyNetQueryBuilder implements SafetyNetQueryBuilderContract
{
    /** @var array<string, string> */
    private array $filters = [];

    /** @var list<list<string>> */
    private array $rows;

    /**
     * @param  list<list<string>>  $rows
     */
    public function __construct(
        array $rows = [],
        private readonly ?FakeAuditService $audit = null,
    ) {
        $this->rows = $rows;
    }

    public function whereLeakKind(string $leakKind): self
    {
        $this->filters['--leak-kind'] = $leakKind;

        return $this;
    }

    public function whereRawLabel(string $rawLabel): self
    {
        $this->filters['--raw-label'] = $rawLabel;

        return $this;
    }

    public function whereMappedClass(string $mappedClass): self
    {
        $this->filters['--mapped-class'] = $mappedClass;

        return $this;
    }

    public function whereFieldPath(string $fieldPath): self
    {
        $this->filters['--field-path'] = $fieldPath;

        return $this;
    }

    public function from(CarbonInterface|string $timestamp): self
    {
        $this->filters['--from'] = $this->normaliseTimestamp($timestamp);

        return $this;
    }

    public function to(CarbonInterface|string $timestamp): self
    {
        $this->filters['--to'] = $this->normaliseTimestamp($timestamp);

        return $this;
    }

    /**
     * The filters applied to this builder, keyed by upstream flag name
     * (`--leak-kind`, `--from`, …). Carbon values are already normalised
     * to ISO 8601 UTC Zulu.
     *
     * @return array<string, string>
     */
    public function appliedFilters(): array
    {
        return $this->filters;
    }

    /**
     * @return list<list<string>>
     */
    public function execute(): array
    {
        $this->audit?->recordSafetyNetQueryCall($this->filters);

        return $this->rows;
    }

    private function normaliseTimestamp(CarbonInterface|string $timestamp): string
    {
        return $timestamp instanceof CarbonInterface
            ? $timestamp->utc()->toIso8601ZuluString()
            : $timestamp;
    }
}
