<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Contracts;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * @internal Narrow process-invocation contract consumed by the audit
 * subsystem (`AuditService`, `PurgeBuilder`, `QueryBuilder`). NOT part of
 * the public adopter API — it exists so the audit builders can depend on
 * an interface instead of the concrete `Gaze` class without promoting the
 * `@internal` runners into `Contracts\Gaze`.
 *
 * These are not generic command runners; each is hard-scoped to its
 * `gaze audit` stage.
 */
interface AuditRunner
{
    /**
     * Audit-purge process invocation, hard-scoped to `gaze audit purge`.
     *
     * @param  list<string>  $command
     */
    public function runForAuditPurge(array $command): ProcessResult;

    /**
     * Audit-query process invocation, hard-scoped to `gaze audit query`.
     *
     * @param  list<string>  $command
     */
    public function runForAuditQuery(array $command): ProcessResult;

    /**
     * Audit-export process invocation, hard-scoped to `gaze audit export`.
     *
     * @param  list<string>  $command
     */
    public function runForAuditExport(array $command): ProcessResult;
}
