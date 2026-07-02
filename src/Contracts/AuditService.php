<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Contracts;

/**
 * Service contract for the `gaze audit` verbs. Concrete implementation
 * lives at `CertaMesh\Gaze\Audit\AuditService`; the test double at
 * `CertaMesh\Gaze\Testing\FakeAuditService`.
 */
interface AuditService
{
    /**
     * Fluent builder for `gaze audit purge`.
     */
    public function purge(): PurgeBuilder;

    /**
     * Fluent builder for `gaze audit query` (and, via
     * `QueryBuilder::export()`, `gaze audit export`).
     */
    public function query(): QueryBuilder;

    /**
     * Fluent builder for `gaze audit safety-net query` — upstream's only
     * `safety-net` subcommand, hence the flattened method name.
     */
    public function safetyNetQuery(): SafetyNetQueryBuilder;
}
