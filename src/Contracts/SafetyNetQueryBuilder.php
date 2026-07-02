<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Contracts;

use Carbon\CarbonInterface;

/**
 * Fluent builder contract for `gaze audit safety-net query` — leak-suspect
 * metadata rows written by the observer-only safety net. Rows follow the
 * upstream TSV contract: each row is a list of column values, and the
 * FIRST row is upstream's header line (column names).
 *
 * Filter methods map 1:1 to upstream flags (pure argv forwarding):
 *
 * | Builder method       | Upstream flag    |
 * |----------------------|------------------|
 * | `whereLeakKind()`    | `--leak-kind`    |
 * | `whereRawLabel()`    | `--raw-label`    |
 * | `whereMappedClass()` | `--mapped-class` |
 * | `whereFieldPath()`   | `--field-path`   |
 * | `from()`             | `--from`         |
 * | `to()`               | `--to`           |
 */
interface SafetyNetQueryBuilder
{
    /**
     * Filter by safety-net leak kind (`--leak-kind`). Fluent.
     */
    public function whereLeakKind(string $leakKind): self;

    /**
     * Filter by raw backend label (`--raw-label`). Fluent.
     */
    public function whereRawLabel(string $rawLabel): self;

    /**
     * Filter by mapped Gaze class (`--mapped-class`). Fluent.
     */
    public function whereMappedClass(string $mappedClass): self;

    /**
     * Filter by structured field path (`--field-path`). Fluent.
     */
    public function whereFieldPath(string $fieldPath): self;

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
     * Run the query and return all matching rows. The first row is
     * upstream's TSV header (column names).
     *
     * @return list<list<string>>
     */
    public function execute(): array;
}
