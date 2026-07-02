<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Audit;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Contracts\AuditRunner;
use CertaMesh\Gaze\Contracts\QueryBuilder as QueryBuilderContract;

/**
 * Runs `gaze audit query` (or `gaze audit export` via `export()`) and, for
 * queries, parses the TSV output into rows. Each row is a list of column
 * values; the outer list is all matching rows and the FIRST row is
 * upstream's header line. Column count and order follow the upstream
 * `gaze audit query` contract.
 *
 * All filter methods are pure argv forwarding — flags are assembled in the
 * upstream `--help` order regardless of call order, so the argv is
 * deterministic. See `Contracts\QueryBuilder` for the method↔flag mapping.
 */
class QueryBuilder implements QueryBuilderContract
{
    /**
     * Default-initialised (not constructor-promoted) so subclasses that skip the
     * parent constructor still inherit safe values and the fluent toggles below.
     */
    protected ?string $whereClass = null;

    protected ?string $whereSource = null;

    protected ?string $whereAction = null;

    protected ?string $whereDocumentKind = null;

    protected ?string $from = null;

    protected ?string $to = null;

    protected ?string $whereSession = null;

    protected bool $hasAmbiguity = false;

    protected ?string $whereAmbiguityReason = null;

    protected ?string $whereCollisionFamily = null;

    protected ?string $whereCollisionVariant = null;

    protected bool $onlyRestoreEvents = false;

    public function __construct(
        protected readonly AuditRunner $gaze,
        protected readonly BinaryResolver $resolver,
        protected readonly string $auditDbPath,
    ) {}

    /**
     * Filter by PII class by forwarding `--class` (named `whereClass()`
     * because `class()` is a PHP reserved word). Fluent.
     */
    public function whereClass(string $piiClass): static
    {
        $this->whereClass = $piiClass;

        return $this;
    }

    /**
     * Filter by source recognizer name by forwarding `--source`. Fluent.
     */
    public function whereSource(string $source): static
    {
        $this->whereSource = $source;

        return $this;
    }

    /**
     * Filter by action by forwarding `--action`. Fluent.
     */
    public function whereAction(string $action): static
    {
        $this->whereAction = $action;

        return $this;
    }

    /**
     * Filter by document kind by forwarding `--document-kind`. Fluent.
     */
    public function whereDocumentKind(string $documentKind): static
    {
        $this->whereDocumentKind = $documentKind;

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
     * Filter by opaque audit session id by forwarding `--session`. Fluent.
     */
    public function whereSession(string $sessionId): static
    {
        $this->whereSession = $sessionId;

        return $this;
    }

    /**
     * Include only rows with an ambiguity side-channel record by forwarding
     * `--has-ambiguity`. Fluent.
     */
    public function hasAmbiguity(): static
    {
        $this->hasAmbiguity = true;

        return $this;
    }

    /**
     * Filter by ambiguity reason by forwarding `--ambiguity-reason`. Fluent.
     */
    public function whereAmbiguityReason(string $reason): static
    {
        $this->whereAmbiguityReason = $reason;

        return $this;
    }

    /**
     * Filter by collision family identifier by forwarding
     * `--collision-family`. Fluent.
     */
    public function whereCollisionFamily(string $family): static
    {
        $this->whereCollisionFamily = $family;

        return $this;
    }

    /**
     * Filter by collision variant identifier by forwarding
     * `--collision-variant`. Fluent.
     */
    public function whereCollisionVariant(string $variant): static
    {
        $this->whereCollisionVariant = $variant;

        return $this;
    }

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
            ...$this->filterArgv(),
        ];

        $result = $this->gaze->runForAuditQuery($command);

        return $this->parseRows($result->output());
    }

    /**
     * Runs `gaze audit export` with the accumulated filter state. Upstream
     * applies the exact same filter flags to `query` and `export`.
     *
     * @param  string|null  $output  Forwarded as `--output`; null exports to stdout (captured on the result).
     * @param  string  $format  Forwarded verbatim as `--format`; the pinned upstream (0.11.x) accepts only `jsonl`.
     */
    public function export(?string $output = null, string $format = 'jsonl'): AuditExportResult
    {
        $command = [
            $this->resolver->resolve(),
            'audit',
            'export',
            '--audit-db='.$this->auditDbPath,
            '--format='.$format,
        ];

        if ($output !== null) {
            $command[] = '--output='.$output;
        }

        $command = [...$command, ...$this->filterArgv()];

        $result = $this->gaze->runForAuditExport($command);

        return new AuditExportResult(
            format: $format,
            path: $output,
            rawOutput: $result->output(),
        );
    }

    /**
     * Filter flags shared by `gaze audit query` and `gaze audit export`,
     * assembled in the upstream `--help` order.
     *
     * @return list<string>
     */
    protected function filterArgv(): array
    {
        $argv = [];

        foreach ([
            '--class' => $this->whereClass,
            '--source' => $this->whereSource,
            '--action' => $this->whereAction,
            '--document-kind' => $this->whereDocumentKind,
            '--from' => $this->from,
            '--to' => $this->to,
            '--session' => $this->whereSession,
        ] as $flag => $value) {
            if ($value !== null) {
                $argv[] = $flag.'='.$value;
            }
        }

        if ($this->hasAmbiguity) {
            $argv[] = '--has-ambiguity';
        }

        foreach ([
            '--ambiguity-reason' => $this->whereAmbiguityReason,
            '--collision-family' => $this->whereCollisionFamily,
            '--collision-variant' => $this->whereCollisionVariant,
        ] as $flag => $value) {
            if ($value !== null) {
                $argv[] = $flag.'='.$value;
            }
        }

        if ($this->onlyRestoreEvents) {
            $argv[] = '--restore-events';
        }

        return $argv;
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
