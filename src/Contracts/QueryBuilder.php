<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Contracts;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\Audit\AuditExportResult;

/**
 * Fluent builder contract for `gaze audit query` / `gaze audit export`.
 * Rows follow the upstream TSV contract: each row is a list of column
 * values, and the FIRST row is upstream's header line (column names).
 *
 * Filter methods map 1:1 to upstream flags (pure argv forwarding — no
 * PHP-side filtering). Value filters use a `where` prefix (PHP reserves
 * `class`, so `--class` becomes `whereClass()`; the rest follow for
 * consistency):
 *
 * | Builder method          | Upstream flag          |
 * |-------------------------|------------------------|
 * | `whereClass()`          | `--class`              |
 * | `whereSource()`         | `--source`             |
 * | `whereAction()`         | `--action`             |
 * | `whereDocumentKind()`   | `--document-kind`      |
 * | `from()`                | `--from`               |
 * | `to()`                  | `--to`                 |
 * | `whereSession()`        | `--session`            |
 * | `hasAmbiguity()`        | `--has-ambiguity`      |
 * | `whereAmbiguityReason()`| `--ambiguity-reason`   |
 * | `whereCollisionFamily()`| `--collision-family`   |
 * | `whereCollisionVariant()`| `--collision-variant` |
 * | `onlyRestoreEvents()`   | `--restore-events`     |
 */
interface QueryBuilder
{
    /**
     * Filter by PII class (`--class`), such as `email`, `name`, or
     * `custom:term`. Named `whereClass()` because `class()` is a PHP
     * reserved word. Fluent.
     */
    public function whereClass(string $piiClass): self;

    /**
     * Filter by source recognizer name (`--source`). Fluent.
     */
    public function whereSource(string $source): self;

    /**
     * Filter by action (`--action`), such as `tokenize`, `redact`, or
     * `preserve`. Fluent.
     */
    public function whereAction(string $action): self;

    /**
     * Filter by document kind (`--document-kind`), such as `text` or
     * `structured`. Fluent.
     */
    public function whereDocumentKind(string $documentKind): self;

    /**
     * Include rows created at or after this timestamp (`--from`). Carbon
     * instances are normalised to ISO 8601 UTC Zulu. Fluent.
     */
    public function from(CarbonInterface|string $timestamp): self;

    /**
     * Include rows created at or before this timestamp (`--to`). Carbon
     * instances are normalised to ISO 8601 UTC Zulu. Fluent.
     */
    public function to(CarbonInterface|string $timestamp): self;

    /**
     * Filter by opaque audit session id (`--session`). Fluent.
     */
    public function whereSession(string $sessionId): self;

    /**
     * Include only rows with an ambiguity side-channel record
     * (`--has-ambiguity`). Fluent.
     */
    public function hasAmbiguity(): self;

    /**
     * Filter by ambiguity reason (`--ambiguity-reason`), such as
     * `no-anchor`. Fluent.
     */
    public function whereAmbiguityReason(string $reason): self;

    /**
     * Filter by collision family identifier (`--collision-family`). Fluent.
     */
    public function whereCollisionFamily(string $family): self;

    /**
     * Filter by collision variant identifier (`--collision-variant`). Fluent.
     */
    public function whereCollisionVariant(string $variant): self;

    /**
     * Restrict the query to restore-telemetry rows. Fluent.
     */
    public function onlyRestoreEvents(): self;

    /**
     * Run the query and return all matching rows. The first row is
     * upstream's TSV header (column names).
     *
     * @return list<list<string>>
     */
    public function execute(): array;

    /**
     * Run `gaze audit export` with the accumulated filter state instead of
     * `gaze audit query`. Upstream applies the exact same filter flags to
     * both subcommands.
     *
     * @param  string|null  $output  Written via upstream `--output`; null exports to stdout (captured on the result).
     * @param  string  $format  Forwarded verbatim as `--format`; the pinned upstream (0.11.x) accepts only `jsonl`.
     */
    public function export(?string $output = null, string $format = 'jsonl'): AuditExportResult;
}
