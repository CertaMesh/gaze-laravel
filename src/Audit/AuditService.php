<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Audit;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Contracts\AuditRunner;
use CertaMesh\Gaze\Contracts\AuditService as AuditServiceContract;
use CertaMesh\Gaze\Exceptions\GazeAuditDbNotConfiguredException;

class AuditService implements AuditServiceContract
{
    public function __construct(
        protected readonly AuditRunner $gaze,
        protected readonly BinaryResolver $resolver,
        protected readonly ?string $auditDbPath,
    ) {}

    public function purge(): PurgeBuilder
    {
        return new PurgeBuilder(
            gaze: $this->gaze,
            resolver: $this->resolver,
            auditDbPath: $this->resolveAuditDbPath(),
        );
    }

    /**
     * Returns a QueryBuilder for `gaze audit query`.
     * Reads from the configured audit DB, or the per-call override passed to `Gaze::audit($auditDbPath)`.
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder(
            gaze: $this->gaze,
            resolver: $this->resolver,
            auditDbPath: $this->resolveAuditDbPath(),
        );
    }

    protected function resolveAuditDbPath(): string
    {
        if ($this->auditDbPath === null || $this->auditDbPath === '') {
            throw new GazeAuditDbNotConfiguredException(
                'gaze audit verbs require gaze.audit_db_path (env GAZE_AUDIT_DB_PATH) to be set, or pass an override to Gaze::audit($path).',
            );
        }

        return $this->auditDbPath;
    }
}
