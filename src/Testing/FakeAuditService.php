<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Audit\AuditService;
use CertaMesh\Gaze\Audit\PurgeBuilder;
use CertaMesh\Gaze\Audit\QueryBuilder;

final class FakeAuditService extends AuditService
{
    /** @var list<array{before: string, dry_run: bool}> */
    private array $purgeCalls = [];

    /**
     * @param  \Closure(string, bool): AuditPurgeResult|null  $purgeHandler
     */
    public function __construct(
        private readonly ?\Closure $purgeHandler = null,
    ) {
        // Deliberately skip parent constructor — fake never invokes process.
    }

    public function purge(): PurgeBuilder
    {
        return new FakePurgeBuilder($this);
    }

    public function query(): QueryBuilder
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
