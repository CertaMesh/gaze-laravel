<?php

declare(strict_types=1);

use Carbon\Carbon;
use CertaMesh\Gaze\Audit\AuditService;
use CertaMesh\Gaze\Gaze;
use Illuminate\Support\Facades\Process;

function configureAuditPurgeCommandGaze(): void
{
    config([
        'gaze.binary' => '/fake/gaze',
        'gaze.audit_db_path' => '/tmp/audit.sqlite',
    ]);
    app()->forgetInstance(Gaze::class);
    app()->forgetInstance(AuditService::class);
}

afterEach(function () {
    Carbon::setTestNow();
});

it('purges with --force, forwarding the exact gaze audit purge argv', function () {
    configureAuditPurgeCommandGaze();
    Process::fake(['*' => Process::result(output: '{"dry_run":false,"matched":5,"deleted":5}'."\n")]);

    $this->artisan('gaze:audit:purge', ['--before' => '2026-01-01T00:00:00Z', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('deleted');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'purge',
            '--audit-db=/tmp/audit.sqlite',
            '--before=2026-01-01T00:00:00Z',
        ]);

        return true;
    });
});

it('forwards --dry-run and needs no confirmation', function () {
    configureAuditPurgeCommandGaze();
    Process::fake(['*' => Process::result(output: '{"dry_run":true,"matched":7,"deleted":0}'."\n")]);

    $this->artisan('gaze:audit:purge', ['--before' => '2026-01-01T00:00:00Z', '--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('dry-run');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'purge',
            '--audit-db=/tmp/audit.sqlite',
            '--before=2026-01-01T00:00:00Z',
            '--dry-run',
        ]);

        return true;
    });
});

it('accepts a relative --before expression and normalises it to ISO 8601 UTC Zulu', function () {
    configureAuditPurgeCommandGaze();
    Carbon::setTestNow(Carbon::parse('2026-07-02T12:00:00Z'));
    Process::fake(['*' => Process::result(output: '{"dry_run":true,"matched":0,"deleted":0}'."\n")]);

    $this->artisan('gaze:audit:purge', ['--before' => '90 days ago', '--dry-run' => true])
        ->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--before=2026-04-03T12:00:00Z');

        return true;
    });
});

it('forwards an --audit-db override instead of the configured path', function () {
    configureAuditPurgeCommandGaze();
    Process::fake(['*' => Process::result(output: '{"dry_run":true,"matched":0,"deleted":0}'."\n")]);

    $this->artisan('gaze:audit:purge', [
        '--before' => '2026-01-01T00:00:00Z',
        '--audit-db' => '/tmp/tenant-a.sqlite',
        '--dry-run' => true,
    ])->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--audit-db=/tmp/tenant-a.sqlite');

        return true;
    });
});

it('returns INVALID when --before is missing', function () {
    configureAuditPurgeCommandGaze();
    Process::fake();

    $this->artisan('gaze:audit:purge')
        ->assertExitCode(2)
        ->expectsOutputToContain('--before');

    Process::assertNothingRan();
});

it('returns INVALID when --before is not parseable', function () {
    configureAuditPurgeCommandGaze();
    Process::fake();

    $this->artisan('gaze:audit:purge', ['--before' => 'not-a-timestamp!!'])
        ->assertExitCode(2)
        ->expectsOutputToContain('Could not parse');

    Process::assertNothingRan();
});

it('asks for confirmation without --force and aborts on decline', function () {
    configureAuditPurgeCommandGaze();
    Process::fake();

    $this->artisan('gaze:audit:purge', ['--before' => '2026-01-01T00:00:00Z'])
        ->expectsConfirmation('Permanently delete audit rows created before 2026-01-01T00:00:00Z?', 'no')
        ->assertExitCode(1)
        ->expectsOutputToContain('Aborted');

    Process::assertNothingRan();
});

it('purges after an accepted confirmation', function () {
    configureAuditPurgeCommandGaze();
    Process::fake(['*' => Process::result(output: '{"dry_run":false,"matched":3,"deleted":3}'."\n")]);

    $this->artisan('gaze:audit:purge', ['--before' => '2026-01-01T00:00:00Z'])
        ->expectsConfirmation('Permanently delete audit rows created before 2026-01-01T00:00:00Z?', 'yes')
        ->assertExitCode(0);

    Process::assertRan(fn ($process): bool => true);
});

it('returns FAILURE when no audit db is configured and no --audit-db override is given', function () {
    config(['gaze.binary' => '/fake/gaze', 'gaze.audit_db_path' => null]);
    app()->forgetInstance(Gaze::class);
    app()->forgetInstance(AuditService::class);
    Process::fake();

    $this->artisan('gaze:audit:purge', ['--before' => '2026-01-01T00:00:00Z', '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('audit_db_path');

    Process::assertNothingRan();
});

it('surfaces an unrecognized stdout shape via rawOutput and still succeeds', function () {
    configureAuditPurgeCommandGaze();
    Process::fake(['*' => Process::result(output: "weird output\n")]);

    $this->artisan('gaze:audit:purge', ['--before' => '2026-01-01T00:00:00Z', '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('weird output');
});
