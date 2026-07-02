<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\Audit\AuditExportResult;
use CertaMesh\Gaze\Contracts\QueryBuilder as QueryBuilderContract;

/**
 * Test double for the query builder. Implements `Contracts\QueryBuilder`
 * directly and returns the pre-seeded rows; every filter method stays a
 * fluent no-op recorder so scripted rows are returned either way. Applied
 * filters are exposed via `appliedFilters()` keyed by upstream flag name.
 * `export()` records the call on the owning `FakeAuditService` (when one
 * is attached) and returns a handler-scripted or empty result.
 */
final class FakeQueryBuilder implements QueryBuilderContract
{
    /** @var array<string, string|true> */
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

    public function whereClass(string $piiClass): self
    {
        $this->filters['--class'] = $piiClass;

        return $this;
    }

    public function whereSource(string $source): self
    {
        $this->filters['--source'] = $source;

        return $this;
    }

    public function whereAction(string $action): self
    {
        $this->filters['--action'] = $action;

        return $this;
    }

    public function whereDocumentKind(string $documentKind): self
    {
        $this->filters['--document-kind'] = $documentKind;

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

    public function whereSession(string $sessionId): self
    {
        $this->filters['--session'] = $sessionId;

        return $this;
    }

    public function hasAmbiguity(): self
    {
        $this->filters['--has-ambiguity'] = true;

        return $this;
    }

    public function whereAmbiguityReason(string $reason): self
    {
        $this->filters['--ambiguity-reason'] = $reason;

        return $this;
    }

    public function whereCollisionFamily(string $family): self
    {
        $this->filters['--collision-family'] = $family;

        return $this;
    }

    public function whereCollisionVariant(string $variant): self
    {
        $this->filters['--collision-variant'] = $variant;

        return $this;
    }

    public function onlyRestoreEvents(): self
    {
        $this->filters['--restore-events'] = true;

        return $this;
    }

    /**
     * Whether onlyRestoreEvents() was toggled — exposed so tests can assert
     * the restriction was requested even though the fake returns scripted rows.
     */
    public function wasRestrictedToRestoreEvents(): bool
    {
        return ($this->filters['--restore-events'] ?? false) === true;
    }

    /**
     * The filters applied to this builder, keyed by upstream flag name
     * (`--class`, `--from`, …). Boolean flags record `true`; value filters
     * record the forwarded string (Carbon values already normalised to
     * ISO 8601 UTC Zulu).
     *
     * @return array<string, string|true>
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
        return $this->rows;
    }

    public function export(?string $output = null, string $format = 'jsonl'): AuditExportResult
    {
        if ($this->audit !== null) {
            return $this->audit->recordExportCall($output, $format, $this->filters);
        }

        return new AuditExportResult(format: $format, path: $output, rawOutput: '');
    }

    private function normaliseTimestamp(CarbonInterface|string $timestamp): string
    {
        return $timestamp instanceof CarbonInterface
            ? $timestamp->utc()->toIso8601ZuluString()
            : $timestamp;
    }
}
