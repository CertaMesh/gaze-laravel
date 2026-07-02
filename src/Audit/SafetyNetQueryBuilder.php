<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Audit;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Contracts\AuditRunner;
use CertaMesh\Gaze\Contracts\SafetyNetQueryBuilder as SafetyNetQueryBuilderContract;

/**
 * Runs `gaze audit safety-net query` and parses the TSV output into rows.
 * Each row is a list of column values; the outer list is all matching
 * leak-suspect metadata rows and the FIRST row is upstream's header line.
 *
 * All filter methods are pure argv forwarding — flags are assembled in the
 * upstream `--help` order regardless of call order, so the argv is
 * deterministic. See `Contracts\SafetyNetQueryBuilder` for the
 * method↔flag mapping.
 */
class SafetyNetQueryBuilder implements SafetyNetQueryBuilderContract
{
    protected ?string $whereLeakKind = null;

    protected ?string $whereRawLabel = null;

    protected ?string $whereMappedClass = null;

    protected ?string $whereFieldPath = null;

    protected ?string $from = null;

    protected ?string $to = null;

    public function __construct(
        protected readonly AuditRunner $gaze,
        protected readonly BinaryResolver $resolver,
        protected readonly string $auditDbPath,
    ) {}

    /**
     * Filter by safety-net leak kind by forwarding `--leak-kind`. Fluent.
     */
    public function whereLeakKind(string $leakKind): static
    {
        $this->whereLeakKind = $leakKind;

        return $this;
    }

    /**
     * Filter by raw backend label by forwarding `--raw-label`. Fluent.
     */
    public function whereRawLabel(string $rawLabel): static
    {
        $this->whereRawLabel = $rawLabel;

        return $this;
    }

    /**
     * Filter by mapped Gaze class by forwarding `--mapped-class`. Fluent.
     */
    public function whereMappedClass(string $mappedClass): static
    {
        $this->whereMappedClass = $mappedClass;

        return $this;
    }

    /**
     * Filter by structured field path by forwarding `--field-path`. Fluent.
     */
    public function whereFieldPath(string $fieldPath): static
    {
        $this->whereFieldPath = $fieldPath;

        return $this;
    }

    /**
     * Include rows created at or after this timestamp by forwarding
     * `--from`. Carbon values are normalised to ISO 8601 UTC Zulu. Fluent.
     */
    public function from(CarbonInterface|string $timestamp): static
    {
        $this->from = $this->normaliseTimestamp($timestamp);

        return $this;
    }

    /**
     * Include rows created at or before this timestamp by forwarding
     * `--to`. Carbon values are normalised to ISO 8601 UTC Zulu. Fluent.
     */
    public function to(CarbonInterface|string $timestamp): static
    {
        $this->to = $this->normaliseTimestamp($timestamp);

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
            'safety-net',
            'query',
            '--audit-db='.$this->auditDbPath,
        ];

        foreach ([
            '--leak-kind' => $this->whereLeakKind,
            '--raw-label' => $this->whereRawLabel,
            '--mapped-class' => $this->whereMappedClass,
            '--field-path' => $this->whereFieldPath,
            '--from' => $this->from,
            '--to' => $this->to,
        ] as $flag => $value) {
            if ($value !== null) {
                $command[] = $flag.'='.$value;
            }
        }

        $result = $this->gaze->runForAuditSafetyNetQuery($command);

        return $this->parseRows($result->output());
    }

    private function normaliseTimestamp(CarbonInterface|string $timestamp): string
    {
        return $timestamp instanceof CarbonInterface
            ? $timestamp->utc()->toIso8601ZuluString()
            : $timestamp;
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
