<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Contracts\AuditService as AuditServiceContract;

/**
 * Test double for the audit verbs. Implements `Contracts\AuditService`
 * directly (no longer extends the concrete `Audit\AuditService`), so it
 * carries no inherited process-invoking state.
 */
final class FakeAuditService implements AuditServiceContract
{
    /** @var list<array{before: string, dry_run: bool}> */
    private array $purgeCalls = [];

    /**
     * @param  \Closure(string, bool): AuditPurgeResult|null  $purgeHandler
     */
    public function __construct(
        private readonly ?\Closure $purgeHandler = null,
    ) {}

    public function purge(): FakePurgeBuilder
    {
        return new FakePurgeBuilder($this);
    }

    public function query(): FakeQueryBuilder
    {
        return new FakeQueryBuilder;
    }

    public function recordPurgeCall(string $before, bool $dryRun): AuditPurgeResult
    {
        $this->purgeCalls[] = ['before' => $before, 'dry_run' => $dryRun];

        if ($this->purgeHandler !== null) {
            return ($this->purgeHandler)($before, $dryRun);
        }

        return new AuditPurgeResult(rawOutput: '', count: 0);
    }

    /**
     * @return list<array{before: string, dry_run: bool}>
     */
    public function purgeCalls(): array
    {
        return $this->purgeCalls;
    }
}
