<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Audit;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Contracts\AuditRunner;
use CertaMesh\Gaze\Contracts\PurgeBuilder as PurgeBuilderContract;

class PurgeBuilder implements PurgeBuilderContract
{
    private ?string $before = null;

    public function __construct(
        protected readonly AuditRunner $gaze,
        protected readonly BinaryResolver $resolver,
        protected readonly string $auditDbPath,
    ) {}

    public function before(CarbonInterface|string $timestamp): self
    {
        $this->before = $timestamp instanceof CarbonInterface
            ? $timestamp->utc()->toIso8601ZuluString()
            : $timestamp;

        return $this;
    }

    public function dryRun(): AuditPurgeResult
    {
        return $this->runPurge(dryRun: true);
    }

    public function execute(): AuditPurgeResult
    {
        return $this->runPurge(dryRun: false);
    }

    protected function runPurge(bool $dryRun): AuditPurgeResult
    {
        if ($this->before === null) {
            throw new \LogicException('PurgeBuilder::before() must be called before dryRun()/execute().');
        }

        $command = [
            $this->resolver->resolve(),
            'audit',
            'purge',
            '--audit-db='.$this->auditDbPath,
            '--before='.$this->before,
        ];

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $result = $this->gaze->runForAuditPurge($command);
        $rawOutput = $result->output();

        [$matched, $deleted] = $this->parseJsonCounts($rawOutput);

        return new AuditPurgeResult(
            rawOutput: $rawOutput,
            count: ($dryRun ? $matched : $deleted) ?? $this->parseLegacyRowCount($rawOutput),
            matched: $matched,
            deleted: $deleted,
        );
    }

    /**
     * The pinned upstream (0.11.x) prints `{"dry_run":bool,"matched":N,"deleted":N}`.
     * Verified against the real binary; parsed opportunistically so an
     * unrecognized stdout shape degrades to nulls with rawOutput intact.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function parseJsonCounts(string $stdout): array
    {
        $decoded = json_decode(trim($stdout), true);

        if (! is_array($decoded)) {
            return [null, null];
        }

        return [
            is_int($decoded['matched'] ?? null) ? $decoded['matched'] : null,
            is_int($decoded['deleted'] ?? null) ? $decoded['deleted'] : null,
        ];
    }

    /**
     * Fallback for pre-JSON stdout shapes ("N rows purged").
     */
    private function parseLegacyRowCount(string $stdout): ?int
    {
        if (preg_match('/(\d+)\s+rows?/', trim($stdout), $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
