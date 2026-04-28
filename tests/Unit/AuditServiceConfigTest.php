<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Audit\AuditService;
use Naoray\GazeLaravel\Exceptions\GazeAuditDbNotConfiguredException;
use Naoray\GazeLaravel\Exceptions\GazeCallerBugException;
use Naoray\GazeLaravel\Queue\Contracts\NonRetryable;

it('throws GazeAuditDbNotConfiguredException when audit_db_path is null and no override', function () {
    $gaze = $this->makeGaze();

    expect(fn () => $gaze->audit()->purge()->before('2026-01-01T00:00:00Z')->dryRun())
        ->toThrow(GazeAuditDbNotConfiguredException::class);
});

it('per-call audit DB override beats null config', function () {
    Process::fake(['*' => Process::result(output: "0 rows would be purged\n")]);

    $gaze = $this->makeGaze();
    $gaze->audit('/tmp/override.sqlite')->purge()->before('2026-01-01T00:00:00Z')->dryRun();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--audit-db=/tmp/override.sqlite');

        return true;
    });
});

it('per-call audit DB override beats config-set value', function () {
    Process::fake(['*' => Process::result(output: "0 rows would be purged\n")]);

    $gaze = $this->makeGaze(auditDbPath: '/tmp/from-config.sqlite');
    $gaze->audit('/tmp/from-call.sqlite')->purge()->before('2026-01-01T00:00:00Z')->dryRun();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--audit-db=/tmp/from-call.sqlite');
        expect($process->command)->not->toContain('--audit-db=/tmp/from-config.sqlite');

        return true;
    });
});

it('GazeAuditDbNotConfiguredException is a caller bug AND NonRetryable', function () {
    $exception = new GazeAuditDbNotConfiguredException(
        'gaze audit purge requires gaze.audit_db_path',
    );

    expect($exception)->toBeInstanceOf(GazeCallerBugException::class);
    expect($exception)->toBeInstanceOf(NonRetryable::class);
    expect($exception->isCallerBug())->toBeTrue();
});

it('Gaze::audit() with no override returns the bound AuditService singleton', function () {
    config(['gaze.audit_db_path' => '/tmp/identity.sqlite']);
    $this->app->forgetInstance(AuditService::class);
    $this->app->forgetInstance(\Naoray\GazeLaravel\Gaze::class);

    $gaze = $this->app->make(\Naoray\GazeLaravel\Gaze::class);
    $a = $gaze->audit();
    $b = $gaze->audit();

    expect($a)->toBe($b);
});

it('Gaze::audit($override) returns a fresh AuditService per call', function () {
    config(['gaze.audit_db_path' => '/tmp/identity.sqlite']);
    $this->app->forgetInstance(AuditService::class);
    $this->app->forgetInstance(\Naoray\GazeLaravel\Gaze::class);

    $gaze = $this->app->make(\Naoray\GazeLaravel\Gaze::class);
    $singleton = $gaze->audit();
    $override = $gaze->audit('/tmp/override.sqlite');

    expect($override)->not->toBe($singleton);
});

it('per-call override does not persist into the next no-override call', function () {
    config(['gaze.audit_db_path' => '/tmp/identity.sqlite']);
    $this->app->forgetInstance(AuditService::class);
    $this->app->forgetInstance(\Naoray\GazeLaravel\Gaze::class);

    $gaze = $this->app->make(\Naoray\GazeLaravel\Gaze::class);
    $singleton1 = $gaze->audit();
    $gaze->audit('/tmp/override.sqlite');
    $singleton2 = $gaze->audit();

    expect($singleton2)->toBe($singleton1);
});
