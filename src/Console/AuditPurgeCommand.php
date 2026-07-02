<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console;

use Carbon\Carbon;
use CertaMesh\Gaze\Contracts\Gaze as GazeContract;
use CertaMesh\Gaze\Exceptions\GazeException;
use Illuminate\Console\Command;

/**
 * Scheduler-friendly wrapper around `Gaze::audit()->purge()` /
 * `gaze audit purge`. Destructive: deletes audit metadata rows older than
 * the cutoff. Without `--dry-run` it requires an interactive confirmation
 * unless `--force` is passed — schedule it as
 * `gaze:audit:purge --before="90 days ago" --force`.
 */
final class AuditPurgeCommand extends Command
{
    protected $signature = 'gaze:audit:purge
        {--before= : Purge rows older than this cutoff — ISO 8601 (2026-01-01T00:00:00Z) or a relative expression ("90 days ago")}
        {--audit-db= : Override the configured gaze.audit_db_path for this run}
        {--dry-run : Count matching rows without deleting them (forwards --dry-run)}
        {--force : Skip the interactive confirmation (required for scheduler/CI use)}';

    protected $description = 'Purge gaze audit metadata rows older than a cutoff via `gaze audit purge`.';

    public function handle(GazeContract $gaze): int
    {
        $rawBefore = $this->option('before');
        if (! is_string($rawBefore) || trim($rawBefore) === '') {
            $this->components->error('The --before option is required, e.g. --before="90 days ago" or --before=2026-01-01T00:00:00Z.');

            return self::INVALID;
        }

        try {
            $cutoff = Carbon::parse(trim($rawBefore))->utc();
        } catch (\Exception) {
            $this->components->error(sprintf('Could not parse --before value "%s" as an ISO 8601 or relative timestamp.', $rawBefore));

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoffIso = $cutoff->toIso8601ZuluString();

        if (! $dryRun && ! (bool) $this->option('force') && ! $this->confirm("Permanently delete audit rows created before {$cutoffIso}?")) {
            $this->components->warn('Aborted — no rows were purged. Pass --force to skip this confirmation (e.g. from the scheduler), or --dry-run to preview.');

            return self::FAILURE;
        }

        $auditDb = $this->option('audit-db');

        try {
            $builder = $gaze->audit(is_string($auditDb) && $auditDb !== '' ? $auditDb : null)
                ->purge()
                ->before($cutoff);

            $result = $dryRun ? $builder->dryRun() : $builder->execute();
        } catch (GazeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('mode', $dryRun ? 'dry-run (nothing deleted)' : 'purge');
        $this->components->twoColumnDetail('cutoff (--before)', $cutoffIso);
        $this->components->twoColumnDetail('matched', $result->matched !== null ? (string) $result->matched : 'n/a');
        $this->components->twoColumnDetail('deleted', $result->deleted !== null ? (string) $result->deleted : 'n/a');

        if ($result->count === null) {
            $this->components->warn('Upstream stdout shape was not recognized; raw output follows.');
            $this->line($result->rawOutput);
        }

        return self::SUCCESS;
    }
}
