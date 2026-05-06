<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Audit;

use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Exceptions\GazeAuditDbNotConfiguredException;
use Naoray\GazeLaravel\Gaze;

class AuditService
{
    public function __construct(
        protected readonly Gaze $gaze,
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
